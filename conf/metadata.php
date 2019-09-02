<?php
/**
 * Options for the oembed plugin
 *
 * @author Dwayne Bent <dbb.pub0@liqd.org>
 * @author nik gaffney <nik@fo.am>
 */

$meta['resolution_priority'] = array('multichoice','_choices' => array('link discovery','provider list'));

$meta['enable_direct_link'] = array('onoff');
$meta['enable_link_discovery'] = array('onoff');
$meta['enable_provider_list']  = array('onoff');

$meta['format_preference'] = array('multichoice','_choices' => array('xml','json'));

$meta['fullwidth_images']  = array('onoff');
