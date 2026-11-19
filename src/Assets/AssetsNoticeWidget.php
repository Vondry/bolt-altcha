<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Assets;

use Bolt\Widget\BaseWidget;
use Bolt\Widget\Injector\AdditionalTarget;
use Bolt\Widget\Injector\RequestZone;
use Bolt\Widget\TwigAwareInterface;

/**
 * A notice on the dashboard while the published widget is missing or outdated. Registered only
 * then (Extension::initialize()).
 */
final class AssetsNoticeWidget extends BaseWidget implements TwigAwareInterface
{
    protected $name = 'ALTCHA notice';
    protected $target = AdditionalTarget::WIDGET_BACK_DASHBOARD_ASIDE_TOP;
    protected $zone = RequestZone::BACKEND;
    protected $priority = 50;
    protected $template = '@bolt-altcha/assets_notice.html.twig';
}
