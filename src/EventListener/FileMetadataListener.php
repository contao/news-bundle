<?php

/**
 * This file is part of Contao.
 *
 * Copyright (c) 2005-2015 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao\NewsBundle\EventListener;

use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\Database;
use Contao\Database\Result;

class FileMetadataListener
{
    /**
     * @var ContaoFrameworkInterface
     */
    private $framework;

    /**
     * Constructor.
     *
     * @param ContaoFrameworkInterface $framework
     */
    public function __construct(ContaoFrameworkInterface $framework)
    {
        $this->framework = $framework;
    }

    /**
     * Adds meta data information to the current request.
     *
     * @param string $ptable The parent table name
     * @param int    $pid    The parent ID name
     *
     * @return Result|false
     */
    public function onAddFileMetaInformationToRequest($ptable, $pid)
    {
        switch ($ptable) {
            case 'tl_news':
                return $this
                    ->getDatabase()
                    ->prepare("SELECT * FROM tl_page WHERE id=(SELECT jumpTo FROM tl_news_archive WHERE id=(SELECT pid FROM tl_news WHERE id=?))")
                    ->execute($pid)
                ;

            case 'tl_news_archive':
                return $this
                    ->getDatabase()
                    ->prepare("SELECT * FROM tl_page WHERE id=(SELECT jumpTo FROM tl_news_archive WHERE id=?)")
                    ->execute($pid)
                ;
        }

        return false;
    }

    /**
     * Returns the database instance.
     *
     * @return Database
     */
    private function getDatabase()
    {
        $this->framework->initialize();

        return $this->framework->getAdapter('Contao\Database')->getInstance();
    }
}
