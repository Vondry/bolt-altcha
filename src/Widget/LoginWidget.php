<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Widget;

use Bolt\Widget\BaseWidget;
use Bolt\Widget\Injector\AdditionalTarget;
use Bolt\Widget\Injector\RequestZone;
use Bolt\Widget\TwigAwareInterface;

/**
 * Puts the widget into the `login_middle` slot of Bolt's login page, between the password and the
 * submit button. Bolt's own template prints the slot, and so must a theme's `login_template`.
 * Renders nothing unless `bolt.login` is on.
 */
final class LoginWidget extends BaseWidget implements TwigAwareInterface
{
    protected $name = 'ALTCHA login';
    protected $target = AdditionalTarget::WIDGET_BACK_LOGIN_MIDDLE;
    protected $zone = RequestZone::BACKEND;
    protected $template = '@bolt-altcha/login_widget.html.twig';
}
