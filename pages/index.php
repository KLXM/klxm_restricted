<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex_addon;
use rex_be_controller;
use rex_view;

$addon = rex_addon::get('klxm_restricted');

echo rex_view::title($addon->i18n('klxm_restricted_title'));

// Render the subpage
rex_be_controller::includeCurrentPageSubPath();
