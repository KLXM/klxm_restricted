<?php

use KLXM\Restricted\Media\BoardShareService;

if (!rex_addon::get('klxm_restricted')->isAvailable()) {
    return;
}

$forcedShareId = (int) 'REX_VALUE[1]';
echo BoardShareService::renderForCurrentArticle($forcedShareId > 0 ? $forcedShareId : null);
