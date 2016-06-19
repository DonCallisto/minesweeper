<?php

namespace spec\AppBundle\Game;

use AppBundle\Exception\OpeningMineBoxException;
use AppBundle\Exception\SchemeManagerException;
use AppBundle\Game\Box;
use AppBundle\Game\BoxInterface;
use AppBundle\Game\MinedBox;
use AppBundle\Game\OpenBoxesStackBuilder;
use PhpSpec\Exception\Example\FailureException;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Prophecy\Prophet;
use Symfony\Component\VarDumper\VarDumper;

class SchemeManagerSpec extends ObjectBehavior
{
    const DEFAULT_ROWS_NUMBER = 16;

    const DEFAULT_COLUMNS_NUMBER = 30;

    const DEFAULT_NUMBER_OF_MINES = 99;

    function it_is_initializable()
    {
        $this->shouldHaveType('AppBundle\Game\SchemeManager');
    }

    function it_creates_a_scheme()
    {
        $scheme = $this->createScheme(self::DEFAULT_ROWS_NUMBER, self::DEFAULT_COLUMNS_NUMBER, self::DEFAULT_NUMBER_OF_MINES);
        $scheme->shouldBeArray();
        $scheme->shouldHaveOnlyBoxes(self::DEFAULT_NUMBER_OF_MINES);
    }

    function it_throws_a_scheme_manager_exception_if_open_a_non_existent_box()
    {
        $scheme = $this->createScheme(self::DEFAULT_ROWS_NUMBER, self::DEFAULT_COLUMNS_NUMBER, self::DEFAULT_NUMBER_OF_MINES);
        $this->shouldThrow(SchemeManagerException::class)->duringOpenBox(-1, -1, $scheme);
    }

    function it_throws_a_opening_mine_box_exception_if_open_a_mined_box()
    {
        $scheme = $this->createScheme(self::DEFAULT_ROWS_NUMBER, self::DEFAULT_COLUMNS_NUMBER, self::DEFAULT_NUMBER_OF_MINES);
        list($rowIndex, $columnIndex) = $this->findBoxFromInstance(MinedBox::class, $scheme->getWrappedObject());
        $this->shouldThrow(OpeningMineBoxException::class)->duringOpenBox($rowIndex, $columnIndex, $scheme);
    }

    function it_opens_a_non_mined_box()
    {
        $prophet = new Prophet();
        $boxDouble = $prophet->prophesize('AppBundle\Game\Box');
        $scheme = [[$boxDouble]];

        $boxDouble->isMine()->shouldBeCalled();
        $boxDouble->getValue()->shouldBeCalled();
        $boxDouble->open()->shouldBeCalled();
        $this->openBox(0, 0, $scheme)->shouldBeAnInstanceOf(OpenBoxesStackBuilder::class);
    }

    function it_returns_mine_scheme()
    {
        $scheme = $this->createScheme(self::DEFAULT_ROWS_NUMBER, self::DEFAULT_COLUMNS_NUMBER, self::DEFAULT_NUMBER_OF_MINES);
        $openBoxesStackBuilder = $this->getMinesScheme($scheme);
        $openBoxesStackBuilder->shouldBeAnInstanceOf(OpenBoxesStackBuilder::class);
        $openBoxesStackBuilder->shouldHaveOnlyMines(self::DEFAULT_NUMBER_OF_MINES);
    }

    function it_checks_schema_opena_status()
    {
        $scheme = $this->createScheme(self::DEFAULT_ROWS_NUMBER, self::DEFAULT_COLUMNS_NUMBER, self::DEFAULT_NUMBER_OF_MINES);
        $this->isSchemaCompleteOpen($scheme)->shouldBeBoolean();
    }

    function it_returns_true_if_schema_is_complete_open()
    {
        $scheme = $this->createScheme(self::DEFAULT_ROWS_NUMBER, self::DEFAULT_COLUMNS_NUMBER, self::DEFAULT_NUMBER_OF_MINES);
        $this->openAllBoxes($scheme->getWrappedObject());
        $this->isSchemaCompleteOpen($scheme)->shouldBeEqualTo(true);
    }

    public function getMatchers()
    {
        return [
            'haveOnlyBoxes' => [$this, 'haveOnlyBoxes'],
            'haveOnlyMines' => [$this, 'haveOnlyMines'],
        ];
    }

    /**
     * @param array $scheme
     * @param integer $numberOfMines
     *
     * @return bool
     *
     * @throws FailureException
     */
    public function haveOnlyBoxes(array $scheme, $numberOfMines)
    {
        $originalNumberOfMines = $numberOfMines;

        foreach ($scheme as $row => $rows) {
            foreach ($rows as $column => $box) {
                if (!$box instanceof BoxInterface) {
                    throw new FailureException("Result array should contains only BoxInterfaces");
                }

                if ($box->isMine()) {
                    $numberOfMines--;
                } else {
                    $this->checkBoxValue($box, $scheme, $row, $column);
                }
            }
        }

        if ($numberOfMines != 0) {
            throw new FailureException(sprintf(
                "Number of mines created is not exact. Expected %s, created %s",
                $originalNumberOfMines,
                $originalNumberOfMines-$numberOfMines
            ));
        }

        return true;
    }

    /**
     * @param BoxInterface $box
     * @param array $scheme
     * @param integer $row
     * @param integer $column
     *
     * @throws FailureException
     */
    private function checkBoxValue(BoxInterface $box, array $scheme, $row, $column)
    {
        $boxValue = $box->getValue();
        $mines = 0;

        for ($rowCycleIndex = -1; $rowCycleIndex <= 1; $rowCycleIndex++) {
            for ($columnCycleIndex = -1; $columnCycleIndex <= 1; $columnCycleIndex++) {
                if ($rowCycleIndex == 0 && $columnCycleIndex == 0) {
                    continue;
                }

                if (!isset($scheme[$row+$rowCycleIndex][$column+$columnCycleIndex])) {
                    continue;
                }

                if (!$scheme[$row+$rowCycleIndex][$column+$columnCycleIndex] instanceof MinedBox) {
                    continue;
                }

                $mines++;
            }
        }

        if ($boxValue != $mines) {
            throw new FailureException(sprintf(
                "Scheme not created correctly at row %s column %s . Expected value of %s, got %s",
                $row,
                $column,
                $mines,
                $boxValue
            ));
        }
    }

    /**
     * @param OpenBoxesStackBuilder $minesStackBuilder
     * @param integer $numberOfMines
     *
     * @return bool
     *
     * @throws FailureException
     */
    public function haveOnlyMines(OpenBoxesStackBuilder $minesStackBuilder, $numberOfMines)
    {
        $originalNumberOfMines = $numberOfMines;

        $minesScheme = $minesStackBuilder->getStackedBoxes();
        foreach ($minesScheme as $row => $rows) {
            foreach ($rows as $column => $box) {
                if (!$box instanceof BoxInterface) {
                    throw new FailureException("Result array should contains only BoxInterfaces");
                }

                if ($box->isMine()) {
                    $numberOfMines--;
                }
            }
        }

        if ($numberOfMines != 0) {
            throw new FailureException(sprintf(
                "Number of mines created is not exact. Expected %s, created %s",
                $originalNumberOfMines,
                $originalNumberOfMines-$numberOfMines
            ));
        }

        return true;
    }

    /**
     * @param string $boxFQCN
     * @param array $scheme
     *
     * @return BoxInterface
     */
    private function findBoxFromInstance($boxFQCN, array $scheme)
    {
        foreach ($scheme as $rowIndex => $row) {
            foreach ($row as $columnIndex => $box) {
                if ($box instanceof $boxFQCN) {
                    return [$rowIndex, $columnIndex];
                }
            }
        }
    }

    /**
     * @param array $scheme
     */
    private function openAllBoxes(array $scheme)
    {
        foreach ($scheme as $row) {
            foreach ($row as $box) {
                if ($box instanceof MinedBox) {
                    continue;
                }

                $box->open();
            }
        }
    }
}
