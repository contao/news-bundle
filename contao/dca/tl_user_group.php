<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

use Contao\CoreBundle\DataContainer\PaletteManipulator;

// Extend the default palette
PaletteManipulator::create()
	->addLegend('news_legend', 'amg_legend', PaletteManipulator::POSITION_BEFORE)
	->addField(array('news', 'newp'), 'news_legend', PaletteManipulator::POSITION_APPEND)
	->applyToPalette('default', 'tl_user_group')
;

// Add fields to tl_user_group
$GLOBALS['TL_DCA']['tl_user_group']['fields']['news'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_user']['news'],
	'inputType'               => 'checkbox',
	'foreignKey'              => 'tl_news_archive.title',
	'eval'                    => array('multiple'=>true),
	'sql'                     => "blob NULL"
);

$GLOBALS['TL_DCA']['tl_user_group']['fields']['newp'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_user']['newp'],
	'inputType'               => 'checkbox',
	'options'                 => array('create', 'delete'),
	'reference'               => &$GLOBALS['TL_LANG']['MSC'],
	'eval'                    => array('multiple'=>true),
	'sql'                     => "blob NULL"
);
