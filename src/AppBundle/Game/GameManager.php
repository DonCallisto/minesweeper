<?php

namespace AppBundle\Game;

use AppBundle\Entity\Game;
use AppBundle\Exception\GameException;
use AppBundle\Exception\GameManagerException;
use AppBundle\Exception\OpeningMineBoxException;
use AppBundle\Exception\SchemeManagerException;
use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Session\Session;

class GameManager
{
    const GAME_ID_SESSION_KEY = 'gameId';

    const DEFAULT_ROWS_NUMBER = 16;

    const DEFAULT_COLUMNS_NUMBER = 30;

    const DEFAULT_NUMBER_OF_MINES = 99;

    const STATUS_CODE_JSON_KEY = 'status_code';

    const OPENED_MINES_JSON_KEY = 'opened_mines';

    const GAME_STATUS_JSON_OK_CODE = 'OK';

    const GAME_STATUS_JSON_KO_CODE = 'ENDED';

    /** @var Session */
    private $session;

    /** @var EntityManager  */
    private $entityManager;

    /** @var SchemeManager */
    private $schemeManager;

    /**
     * @param Session $session
     * @param EntityManager $entityManager
     * @param SchemeManager $schemeManager
     */
    public function __construct(Session $session, EntityManager $entityManager, SchemeManager $schemeManager)
    {
        $this->session = $session;
        $this->entityManager = $entityManager;
        $this->schemeManager = $schemeManager;
    }

    /**
     * @return string
     */
    public function newGame()
    {
        $this->handlePreviousGame();

        $game = new Game();
        $game->setScheme($this->schemeManager->createScheme(
            self::DEFAULT_ROWS_NUMBER,
            self::DEFAULT_COLUMNS_NUMBER,
            self::DEFAULT_NUMBER_OF_MINES
        ));

        $gameId = $game->getId();
        $this->setGameUuidInSession($gameId);

        $this->saveGame($game);

        return $gameId;
    }

    /**
     * @param $gameId
     */
    protected function setGameUuidInSession($gameId)
    {
        $this->session->set(self::GAME_ID_SESSION_KEY, $gameId);
    }

    /**
     * @return mixed
     */
    protected function getGameUuidFromSession()
    {
        return $this->session->get(self::GAME_ID_SESSION_KEY);
    }

    protected function clearGameFromSession()
    {
        $this->session->clear(self::GAME_ID_SESSION_KEY);
    }

    /**
     * @param Game $game
     */
    protected function saveGame(Game $game)
    {
        $this->entityManager->persist($game);
        $this->entityManager->flush();
    }

    /**
     * @param Game $game
     * @param $status
     *
     * @return \DateInterval
     *
     * @throws GameManagerException
     */
    protected function endGame(Game $game, $status)
    {
        try {
            $totalGamingTime = $game->end($status);
            $this->entityManager->flush($game);
            $this->clearGameFromSession();

            return $totalGamingTime;
        } catch (GameException $e) {
            throw new GameManagerException($e->getMessage());
        }
    }

    /**
     * @param $gameId
     *
     * @return Game
     */
    protected function getGameFromId($gameId)
    {
        return $this->getGameRepository()->findOneBy([
            'id' => $gameId
        ]);
    }

    /**
     * @return \Doctrine\ORM\EntityRepository
     */
    protected function getGameRepository()
    {
        return $this->entityManager->getRepository('AppBundle:Game');
    }

    protected function handlePreviousGame()
    {
        $gameId = $this->getGameUuidFromSession();
        if (!$gameId) {
            return;
        }

        $previousGame = $this->getGameFromId($gameId);
        if (!$previousGame) { // Maybe game was not saved
            return;
        }

        if ($previousGame->getStatus() == Game::STATUS_STARTED) {
            $this->endGame($previousGame, Game::STATUS_FAILED);
        }

        $this->entityManager->flush($previousGame);
    }

    /**
     * @param $row
     * @param $column
     * @return array|bool
     *
     * @throws GameManagerException
     * @throws SchemeManagerException
     */
    public function open($row, $column)
    {
        if ($this->isRowOrColumnInvalid($row, $column)) {
            return null;
        }

        $gameId = $this->session->get(self::GAME_ID_SESSION_KEY);
        $game = $this->getGameFromId($gameId);
        $scheme = $game->getScheme();

        try {
            $openBoxesStackBuilder = $this->schemeManager->openBox($row, $column, $scheme);
            $openedScheme = $openBoxesStackBuilder->getScheme();
            $game->setScheme($openedScheme);

            if ($this->schemeManager->isSchemaCompleteOpen($openedScheme)) {
                $game->end(Game::STATUS_CLEARED);
            }

            $this->entityManager->flush($game);

            return $this->createArrayForJsonOpenResult($openBoxesStackBuilder, self::GAME_STATUS_JSON_OK_CODE);
        } catch (OpeningMineBoxException $e) {
            $this->endGame($game, Game::STATUS_FAILED);

            return $this->createArrayForJsonOpenResult($this->schemeManager->getMinesScheme($scheme), self::GAME_STATUS_JSON_KO_CODE);
        }
    }

    /**
     * @param $row
     * @param $column
     *
     * @return bool
     */
    private function isRowOrColumnInvalid($row, $column)
    {
        if ($this->rowIsInvalid($row)) {
            return true;
        }

        if ($this->columnInInvalid($column)) {
            return true;
        }

        return false;
    }

    /**
     * @param $row
     *
     * @return bool
     */
    private function rowIsInvalid($row)
    {
        if ($row < 0) {
            return true;
        }

        if ($row > self::DEFAULT_ROWS_NUMBER) {
            return true;
        }

        return false;
    }

    /**
     * @param $column
     *
     * @return bool
     */
    private function columnInInvalid($column)
    {
        if ($column < 0) {
            return true;
        }

        if ($column > self::DEFAULT_COLUMNS_NUMBER) {
            return true;
        }

        return false;
    }

    /**
     * @return Game
     *
     * @throws GameManagerException
     */
    public function getScheme()
    {
        $game = $this->getGameFromId($this->getGameUuidFromSession());

        if (!$game) {
            throw new GameManagerException("You should start a game before calling this");
        }

        return $game->getScheme();
    }

    /**
     * @param OpenBoxesStackBuilder|null $openBoxesStackBuilder
     * @param string $gameStatus
     *
     * @return array
     */
    protected function createArrayForJsonOpenResult(OpenBoxesStackBuilder $openBoxesStackBuilder, $gameStatus)
    {
        $result = [
            self::STATUS_CODE_JSON_KEY => $gameStatus,
            self::OPENED_MINES_JSON_KEY => [],
        ];

        foreach ($openBoxesStackBuilder->getStackedBoxes() as $rowIndex => $columns) {
            foreach ($columns as $columnIndex => $box) {
                /** @var $box BoxInterface */
                $result[self::OPENED_MINES_JSON_KEY]["r_$rowIndex"]["c_$columnIndex"] = $box->getValue();
            }
        }

        return $result;
    }
}
