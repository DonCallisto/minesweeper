<?php

namespace AppBundle\Game;

use AppBundle\Exception\OpenBoxesStackBuilderException;

class OpenBoxesStackBuilder
{
    /** @var array */
    private $scheme;

    /** @var array */
    private $openedBoxes;

    /**
     * @param array $scheme
     */
    public function __construct(array $scheme)
    {
        $this->scheme = $scheme;
        $this->openedBoxes = [];
    }

    /**
     * @return array
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * @param $rowIndex
     * @param $columnIndex
     * @param BoxInterface $box
     *
     * @return OpenBoxesStackBuilder
     *
     * @throws OpenBoxesStackBuilderException
     */
    public function addBox($rowIndex, $columnIndex, BoxInterface $box)
    {
        if (!isset($this->scheme[$rowIndex][$columnIndex])) {
            throw new OpenBoxesStackBuilderException("You can't add a box that is out of scheme range");
        }

        // update the box into scheme
        $this->scheme[$rowIndex][$columnIndex] = $box;
        $this->openedBoxes[$rowIndex][$columnIndex] = $box;

        return $this;
    }

    /**
     * @return array
     */
    public function getStackedBoxes()
    {
        return $this->openedBoxes;
    }
}
