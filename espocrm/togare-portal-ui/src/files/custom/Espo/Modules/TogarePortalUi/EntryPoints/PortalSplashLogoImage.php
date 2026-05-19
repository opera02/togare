<?php

declare(strict_types=1);

namespace Espo\Modules\TogarePortalUi\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\NotFound;
use Espo\EntryPoints\Image;

/**
 * Public pre-auth image entry point for the PortalSplash logo.
 *
 * The stock LogoImage entry point only allows the core `companyLogo` field.
 * PortalSplash uses its own Settings image field, so it needs the same
 * constrained NoAuth path with an allowed field list scoped to Togare.
 */
class PortalSplashLogoImage extends Image
{
    use NoAuth;

    protected $allowedRelatedTypeList = ['Settings'];
    protected $allowedFieldList = ['togarePortalSplashLogo'];

    public function run(Request $request, Response $response): void
    {
        $id = $request->getQueryParam('id');
        $size = $request->getQueryParam('size') ?? null;

        if (! $id) {
            $id = $this->config->get('togarePortalSplashLogoId');
        }

        if (! $id) {
            throw new NotFound('No id.');
        }

        $this->show($response, $id, $size);
    }
}
