<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Assets;

use Bolt\Widget\BaseWidget;
use Bolt\Widget\Injector\AdditionalTarget;
use Bolt\Widget\Injector\RequestZone;
use Bolt\Widget\TwigAwareInterface;

/**
 * A notice on the dashboard while the project's copy of the extension's services is outdated
 * (ServicesStatus). Registered only then (Extension::initialize()).
 */
final class ServicesNoticeWidget extends BaseWidget implements TwigAwareInterface
{
    protected $name = 'ALTCHA services notice';
    protected $target = AdditionalTarget::WIDGET_BACK_DASHBOARD_ASIDE_TOP;
    protected $zone = RequestZone::BACKEND;
    protected $priority = 50;
    protected $template = '@bolt-altcha/services_notice.html.twig';
}
