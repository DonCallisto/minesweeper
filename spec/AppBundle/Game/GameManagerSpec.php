<?php

namespace spec\AppBundle\Game;

use AppBundle\Entity\Game;
use AppBundle\Exception\GameManagerException;
use AppBundle\Exception\OpeningMineBoxException;
use AppBundle\Game\Box;
use AppBundle\Game\BoxInterface;
use AppBundle\Game\GameManager;
use AppBundle\Game\MinedBox;
use AppBundle\Game\OpenBoxesStackBuilder;
use AppBundle\Game\SchemeManager;
use Doctrine\ORM\EntityManager;
use PhpSpec\Exception\Example\FailureException;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Prophecy\Prophet;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Session\Session;

class GameManagerSpec extends ObjectBehavior
{
    const UUID_LENGTH = 36;

    const UNCORRECT_GAME_STATUS = -1;

    const GAME_ID_SESSION_KEY = 'gameId';

    const FAKE_SCHEME_ROWS = 8;

    const FAKE_SCHEME_COLUMNS = 8;

    const FAKE_SCHEME_MINES_NUMBER = 10;

    /** @var Prophet */
    private $prophet;

    private $game;

    private $gameRepo;

    function let(Session $session, EntityManager $entityManager, SchemeManager $schemeManager)
    {
        $this->beConstructedWith($session, $entityManager, $schemeManager);

        $this->prophet = new Prophet;
        $this->game = $this->prophet->prophesize('AppBundle\Entity\Game');
        $this->gameRepo = $this->prophet->prophesize('AppBundle\Repository\GameRepository');
    }

    function letGo()
    {
        $this->prophet->checkPredictions();
    }

    function it_is_initializable()
    {
        $this->shouldHaveType('AppBundle\Game\GameManager');
    }

    function it_creates_a_game(Session $session, EntityManager $entityManager, SchemeManager $schemeManager)
    {
        $schemeDouble = $this->createASchemeDouble();
        $schemeManager->createScheme(Argument::any(), Argument::any(), Argument::any())->willReturn($schemeDouble);

        $gameId = $this->newGame();
        $gameId->shouldBeAUuid();

        $session->set(self::GAME_ID_SESSION_KEY, $gameId->getWrappedObject())->shouldHaveBeenCalled();
        $entityManager->persist(Argument::Type(Game::class))->shouldHaveBeenCalled();
        $entityManager->flush()->shouldHaveBeenCalled();
    }

    function it_creates_a_game_and_save_previous_one_with_failure_status(
        Session $session,
        EntityManager $entityManager,
        SchemeManager $schemeManager
    ) {
        $previousGameId = Uuid::uuid4()->toString();
        $previousGame = $this->prophet->prophesize('AppBundle\Entity\Game');

        $session->get(self::GAME_ID_SESSION_KEY)->shouldBeCalled();
        $session->get(self::GAME_ID_SESSION_KEY)->willReturn($previousGameId);

        $entityManager->getRepository('AppBundle:Game')->willReturn($this->gameRepo);
        $this->gameRepo->findOneBy(['id' => $previousGameId])->willReturn($previousGame);

        $previousGame->getStatus()->willReturn(Game::STATUS_STARTED);
        $previousGame->end(Game::STATUS_FAILED)->shouldBeCalled();

        $entityManager->flush($previousGame)->shouldBeCalled();

        $schemeDouble = $this->createASchemeDouble();
        $schemeManager->createScheme(Argument::any(), Argument::any(), Argument::any())->willReturn($schemeDouble);

        $session->set(self::GAME_ID_SESSION_KEY, Argument::any())->shouldBeCalled();
        $session->clear(GameManager::GAME_ID_SESSION_KEY)->shouldBeCalled();
        $entityManager->persist(Argument::Type(Game::class))->shouldBeCalled();
        $entityManager->flush()->shouldBeCalled();

        $this->newGame();
    }

    function it_opens_a_box_correctly(Session $session, EntityManager $entityManager, SchemeManager $schemeManager)
    {
        $schemeDouble =  $this->createASchemeDouble();
        $openedSchemeDouble = $this->openSchemeDouble();

        $openBoxesStackBuilderDouble = $this->prophet->prophesize('AppBundle\Game\OpenBoxesStackBuilder');
        $openBoxesStackBuilderDouble->getScheme()->willReturn($openedSchemeDouble);

        $this->retrieveGameExpectationsAndPromises($session, $entityManager, $schemeManager);
        $schemeManager->openBox(0, 0, $schemeDouble)->shouldBeCalled();
        $schemeManager->openBox(0, 0, $schemeDouble)->willReturn($openBoxesStackBuilderDouble);

        $openBoxesStackBuilderDouble->getStackedBoxes()->willReturn([0 => [0 => $schemeDouble[0][0]]]);

        $this->game->setScheme($openedSchemeDouble)->shouldBeCalled();

        $schemeManager->isSchemaCompleteOpen($openedSchemeDouble)->shouldBeCalled();

        $entityManager->flush($this->game)->shouldBeCalled();

        $result = $this->open(0, 0);
        $result[GameManager::STATUS_CODE_JSON_KEY]->shouldBeEqualTo(GameManager::GAME_STATUS_JSON_OK_CODE);
        $result[GameManager::OPENED_MINES_JSON_KEY]->shouldBeEqualTo(["r_0" => ["c_0" => 0]]);
    }

    function it_opens_last_box(Session $session, EntityManager $entityManager, SchemeManager $schemeManager)
    {
        $schemeDouble =  $this->createASchemeDouble();
        $clearedSchemeDouble = $this->clearedSchemeDouble();

        $openBoxesStackBuilderDouble = $this->prophet->prophesize('AppBundle\Game\OpenBoxesStackBuilder');
        $openBoxesStackBuilderDouble->getScheme()->willReturn($clearedSchemeDouble);

        $this->retrieveGameExpectationsAndPromises($session, $entityManager, $schemeManager);
        $schemeManager->openBox(0, 0, $schemeDouble)->shouldBeCalled();
        $schemeManager->openBox(0, 0, $schemeDouble)->willReturn($openBoxesStackBuilderDouble);

        $openBoxesStackBuilderDouble->getStackedBoxes()->willReturn([0 => [0 => $schemeDouble[0][0]]]);

        $this->game->setScheme($clearedSchemeDouble)->shouldBeCalled();

        $schemeManager->isSchemaCompleteOpen($clearedSchemeDouble)->shouldBeCalled();
        $schemeManager->isSchemaCompleteOpen($clearedSchemeDouble)->willReturn(true);
        $this->game->end(Game::STATUS_CLEARED)->shouldBeCalled();

        $entityManager->flush($this->game)->shouldBeCalled();

        $result = $this->open(0, 0);
        $result[GameManager::STATUS_CODE_JSON_KEY]->shouldBeEqualTo(GameManager::GAME_STATUS_JSON_OK_CODE);
        $result[GameManager::OPENED_MINES_JSON_KEY]->shouldBeEqualTo(["r_0" => ["c_0" => 0]]);
    }

    function it_ends_a_game_after_open_a_mine(Session $session, EntityManager $entityManager, SchemeManager $schemeManager)
    {
        $schemeDouble =  $this->createASchemeDouble();
        $mineBoxesStackBuilder = new OpenBoxesStackBuilder($schemeDouble);

        $this->retrieveGameExpectationsAndPromises($session, $entityManager, $schemeManager);
        $schemeManager->openBox(3, 1, $schemeDouble)->shouldBeCalled();
        $schemeManager->openBox(3, 1, $schemeDouble)->willThrow(OpeningMineBoxException::class);

        $this->game->end(Game::STATUS_FAILED)->shouldBeCalled();
        $entityManager->flush($this->game)->shouldBeCalled();
        $session->clear(GameManager::GAME_ID_SESSION_KEY)->shouldBeCalled();

        $schemeManager->getMinesScheme($schemeDouble)->willReturn($mineBoxesStackBuilder);
        $schemeManager->getMinesScheme($schemeDouble)->shouldBeCalled();

        $result = $this->open(3, 1);
        $result[GameManager::STATUS_CODE_JSON_KEY]->shouldBeEqualTo(GameManager::GAME_STATUS_JSON_KO_CODE);
    }

    function it_does_nothing_if_try_to_open_a_non_existent_box()
    {
        $this->open(-1, 0)->shouldReturn(null);
    }

    function it_returns_a_scheme_after_game_creation(Session $session, EntityManager $entityManager, SchemeManager $schemeManager)
    {
        $this->retrieveGameExpectationsAndPromises($session, $entityManager, $schemeManager);
        $this->getScheme()->shouldBeArray();
    }

    function it_throws_exception_if_get_scheme_on_nonstarted_game(Session $session, EntityManager $entityManager)
    {
        $session->get(self::GAME_ID_SESSION_KEY)->willReturn(null);
        $entityManager->getRepository('AppBundle:Game')->willReturn($this->gameRepo);
        $this->gameRepo->findOneBy(['id' => null])->willReturn(null);

        $this->shouldThrow(GameManagerException::class)->duringGetScheme();
    }

    public function getMatchers()
    {
        return [
            'beAUuid' => function ($value) {
                if (!is_string($value)) {
                    throw new FailureException(sprintf(
                        'Expected a string but got ... ?'
                    ));
                }

                if (strlen($value) != self::UUID_LENGTH) {
                    throw new FailureException(sprintf(
                        'Expected a Uuid but got ... ?'
                    ));
                }

                return true;
            },
        ];
    }

    /**
     * @param Session $session
     * @param EntityManager $entityManager
     * @param SchemeManager $schemeManager
     */
    private function retrieveGameExpectationsAndPromises(
        Session $session,
        EntityManager $entityManager,
        SchemeManager $schemeManager
    ) {
        $schemeDouble = $this->createASchemeDouble();
        $schemeManager->createScheme(Argument::any(), Argument::any(), Argument::any())->willReturn($schemeDouble);

        $gameId = $this->newGame()->getWrappedObject();

        $session->set(self::GAME_ID_SESSION_KEY, $gameId)->shouldBeCalled();
        $session->get(self::GAME_ID_SESSION_KEY)->willReturn($gameId);

        $entityManager->getRepository('AppBundle:Game')->willReturn($this->gameRepo);
        $this->gameRepo->findOneBy(['id' => $gameId])->willReturn($this->game);

        $this->game->getScheme()->willReturn($schemeDouble);
    }

    /**
     * @return array
     */
    private function createASchemeDouble()
    {
        return $this->getSchemeDouble();
    }

    /**
     * @return array
     */
    private function getSchemeDouble()
    {
        return [
            [new Box(0), new Box(0), new Box(1), new Box(1), new Box(1), new Box(0), new Box(0), new Box(0)],
            [new Box(0), new Box(0), new Box(1), new MinedBox(), new Box(2), new Box(1), new Box(1), new Box(0)],
            [new Box(1), new Box(1), new Box(3), new Box(3), new Box(4), new MinedBox(), new Box(1), new Box(0)],
            [new Box(1), new MinedBox(), new Box(2), new MinedBox(), new MinedBox(), new Box(3), new Box(2), new Box(0)],
            [new Box(1), new Box(1), new Box(3), new Box(3), new Box(4), new MinedBox(), new Box(1), new Box(0)],
            [new Box(1), new Box(1), new Box(1), new MinedBox(), new Box(2), new Box(1), new Box(2), new Box(1)],
            [new MinedBox(), new Box(1), new Box(1), new Box(1), new Box(1), new Box(0), new Box(1), new MinedBox()],
            [new MinedBox(), new Box(2), new Box(0), new Box(0), new Box(0), new Box(0), new Box(1), new Box(1)],
        ];
    }

    /**
     * @return array
     */
    private function openSchemeDouble()
    {
        $schemeDouble = $this->getSchemeDouble();
        /** @var $box BoxInterface */
        $box = $schemeDouble[0][2];

        $box->open();

        return $schemeDouble;
    }

    /**
     * @return array
     */
    private function clearedSchemeDouble()
    {
        $schemeDouble = $this->getSchemeDouble();
        foreach ($schemeDouble as $rows) {
            foreach($rows as $box) {
                if ($box instanceof MinedBox) {
                    continue;
                }

                $box->open();
            }
        }

        return $schemeDouble;
    }
}
