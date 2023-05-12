<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

use Contao\CoreBundle\Controller\BackendCsvImportController;
use Contao\ModuleNewsArchive;
use Contao\ModuleNewsList;
use Contao\ModuleNewsMenu;
use Contao\ModuleNewsReader;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Contao\System;
use Symfony\Component\HttpFoundation\Request;

// Back end modules
$GLOBALS['BE_MOD']['content']['news'] = array
(
	'tables'      => array('tl_news_archive', 'tl_news', 'tl_content'),
	'table'       => array(BackendCsvImportController::class, 'importTableWizardAction'),
	'list'        => array(BackendCsvImportController::class, 'importListWizardAction')
);

// Front end modules
$GLOBALS['FE_MOD']['news'] = array
(
	'newslist'    => ModuleNewsList::class,
	'newsreader'  => ModuleNewsReader::class,
	'newsarchive' => ModuleNewsArchive::class,
	'newsmenu'    => ModuleNewsMenu::class
);

// Style sheet
if (System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest(System::getContainer()->get('request_stack')->getCurrentRequest() ?? Request::create('')))
{
	$GLOBALS['TL_CSS'][] = 'bundles/contaonews/news.min.css|static';
}

// Add permissions
$GLOBALS['TL_PERMISSIONS'][] = 'news';
$GLOBALS['TL_PERMISSIONS'][] = 'newp';

// Models
$GLOBALS['TL_MODELS']['tl_news_archive'] = NewsArchiveModel::class;
$GLOBALS['TL_MODELS']['tl_news'] = NewsModel::class;
