<?php

namespace AppBundle\Game;

use AppBundle\Exception\OpeningMineBoxException;
use AppBundle\Exception\SchemeManagerException;
use Symfony\Component\VarDumper\VarDumper;

class SchemeManager
{
    /**
     * Will keep a mines scheme populated after createMinesScheme() call.
     *
     * @var array
     */
    private $mines = [];

    /**
     * @param integer $rows
     * @param integer $columns
     * @param integer $minesNumber
     *
     * @return array
     */
    public function createScheme($rows, $columns, $minesNumber)
    {
        $this->createMinesScheme($rows, $columns, $minesNumber);

        $scheme = [];
        for ($rowNumber = 0; $rowNumber < $rows; $rowNumber++) {
            for ($columnNumber = 0; $columnNumber < $columns; $columnNumber++) {
                $box = $this->getBox($rowNumber, $columnNumber);
                $scheme[$rowNumber][$columnNumber] = $box;
            }
        }

        // $this->dumpVanillaScheme($scheme);
        return $scheme;
    }

    /**
     * @param integer $rowIndex
     * @param integer $columnIndex
     * @param array $scheme
     *
     * @return array
     *
     * @throws SchemeManagerException
     * @throws OpeningMineBoxException
     */
    public function openBox($rowIndex, $columnIndex, array $scheme)
    {
        if (!isset($scheme[$rowIndex][$columnIndex])) {
            throw new SchemeManagerException("You've requested to open a non-existent box");
        }

        /** @var $box BoxInterface */
        $box = $scheme[$rowIndex][$columnIndex];

        if ($box->isMine()) {
            $box->open(); // will throw an exception
        }

        $value = $box->getValue();

        if ($value === 0) {
            $this->recursiveOpen($rowIndex, $columnIndex, $scheme);
        } else {
            $box->open();
        }

        // $this->dumpGameScheme($scheme);
        return $scheme;
    }

    /**
     * @param integer $rowIndex
     * @param integer $columnIndex
     * @param array $scheme
     *
     * @return bool
     */
    protected function recursiveOpen($rowIndex, $columnIndex, array $scheme)
    {
        if (!isset($scheme[$rowIndex][$columnIndex])) {
            return;
        }

        /** @var $box BoxInterface */
        $box = $scheme[$rowIndex][$columnIndex];

        if ($box->isMine()) {
            return;
        }

        if ($box->isOpen()) {
            return;
        }

        $value = $box->open();

        if ($value !== 0) {
            return;
        }

        return $this->recursiveOpen($rowIndex-1, $columnIndex-1, $scheme) ||
            $this->recursiveOpen($rowIndex-1, $columnIndex, $scheme) ||
            $this->recursiveOpen($rowIndex-1, $columnIndex+1, $scheme) ||
            $this->recursiveOpen($rowIndex, $columnIndex-1, $scheme) ||
            $this->recursiveOpen($rowIndex, $columnIndex+1, $scheme) ||
            $this->recursiveOpen($rowIndex+1, $columnIndex-1, $scheme) ||
            $this->recursiveOpen($rowIndex+1, $columnIndex, $scheme) ||
            $this->recursiveOpen($rowIndex+1, $columnIndex+1, $scheme);
    }

    /**
     * @param integer $rowsNumber
     * @param integer $columnsNumber
     * @param integer $minesNumber
     *
     * @return bool
     */
    protected function createMinesScheme($rowsNumber, $columnsNumber, $minesNumber)
    {
        $mines = [];
        while ($minesNumber) {
            $row = rand(0, $rowsNumber-1);
            $column = rand(0, $columnsNumber-1);

            if (!isset($mines[$row][$column])) {
                $mines[$row][$column] = true;
                $minesNumber--;
            }
        }

        $this->mines = $mines;

        return true;
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return BoxInterface
     */
    protected function getBox($rowNumber, $columnNumber)
    {
        // @todo: throw an exception if scheme is not initialized?

        if ($this->checkIfGotMine($rowNumber, $columnNumber)) {
            $box = new MinedBox();
        } else {
            $mine = 0;
            if ($this->checktTopLeftBox($rowNumber, $columnNumber)) {
                $mine++;
            }
            if ($this->checkAboveBox($rowNumber, $columnNumber)) {
                $mine++;
            }
            if ($this->checkTopRightBox($rowNumber, $columnNumber)) {
                $mine++;
            }
            if ($this->checkLeftBox($rowNumber, $columnNumber)) {
                $mine++;
            }
            if ($this->checkRightBox($rowNumber, $columnNumber)) {
                $mine++;
            }
            if ($this->checkBottomLeftBox($rowNumber, $columnNumber)) {
                $mine++;
            }
            if ($this->checkBelowBox($rowNumber, $columnNumber)) {
                $mine++;
            }
            if ($this->checkBottomRightBox($rowNumber, $columnNumber)) {
                $mine++;
            }

            $box = new Box($mine);
        }

        return $box;
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return bool
     */
    protected function checkIfGotMine($rowNumber, $columnNumber)
    {
        return isset($this->mines[$rowNumber][$columnNumber]);
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return bool
     */
    protected function checktTopLeftBox($rowNumber, $columnNumber)
    {
        return isset($this->mines[$rowNumber-1][$columnNumber-1]);
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return bool
     */
    protected function checkAboveBox($rowNumber, $columnNumber)
    {
        return isset($this->mines[$rowNumber-1][$columnNumber]);
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return bool
     */
    protected function checkTopRightBox($rowNumber, $columnNumber)
    {
        return isset($this->mines[$rowNumber-1][$columnNumber+1]);
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return bool
     */
    protected function checkLeftBox($rowNumber, $columnNumber)
    {
        return isset($this->mines[$rowNumber][$columnNumber-1]);
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return bool
     */
    protected function checkRightBox($rowNumber, $columnNumber)
    {
        return isset($this->mines[$rowNumber][$columnNumber+1]);
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return bool
     */
    protected function checkBottomLeftBox($rowNumber, $columnNumber)
    {
        return isset($this->mines[$rowNumber+1][$columnNumber-1]);
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return bool
     */
    protected function checkBelowBox($rowNumber, $columnNumber)
    {
        return isset($this->mines[$rowNumber+1][$columnNumber]);
    }

    /**
     * @param integer $rowNumber
     * @param integer $columnNumber
     *
     * @return bool
     */
    protected function checkBottomRightBox($rowNumber, $columnNumber)
    {
        return isset($this->mines[$rowNumber+1][$columnNumber+1]);
    }

    /**
     * For debug purposes only. Vanilla scheme is just the underlying
     * scheme. Not the scheme representation game.
     *
     * @param array $scheme
     */
    protected function dumpVanillaScheme(array $scheme)
    {
        foreach ($scheme as $row) {
            $rowString = '';
            foreach ($row as $box) {
                /** @var $box BoxInterface */
                $value = $box->isMine() ? 'X' : $box->getValue();
                $rowString .= $value.' ';
            }
            VarDumper::dump($rowString);
        }
    }

    /**
     * For debug purposes only. Game scheme is the representation
     * of scheme during a game.
     *
     * @param array $scheme
     */
    protected function dumpGameScheme(array $scheme)
    {
        foreach ($scheme as $row) {
            $rowString = '';
            foreach ($row as $box) {
                /** @var $box BoxInterface */
                if ($box->isMine()) {
                    $value = 'X';
                } else {
                    $value = $box->isOpen() ? $box->getValue() : '-';
                }
                $rowString .= $value.' ';
            }
            VarDumper::dump($rowString);
        }
    }
}