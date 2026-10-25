<?php

declare(strict_types=1);

namespace App\Identity\Ui\ApiDoc;

use Nelmio\ApiDocBundle\Describer\DescriberInterface;
use Nelmio\ApiDocBundle\OpenApiPhp\Util;
use OpenApi\Annotations as OA;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('nelmio_api_doc.describer', ['priority' => -500])]
final class SessionRequestProtectionDescriber implements DescriberInterface
{
    public function describe(OA\OpenApi $api): void
    {
        if (!\is_array($api->paths)) {
            return;
        }
        foreach ($api->paths as $path) {
            if (!\is_string($path->path) || !str_starts_with($path->path, '/api/') || str_starts_with($path->path, '/api/extension/') || str_starts_with($path->path, '/api/internal/')) {
                continue;
            }
            foreach (['post', 'put', 'patch', 'delete'] as $method) {
                $operation = $path->{$method};
                if (!$operation instanceof OA\Operation) {
                    continue;
                }
                $parameter = Util::getOperationParameter($operation, 'X-Conwix-Request', 'header');
                $parameter->required = true;
                $parameter->description = 'Session request protection. Origin must match this application; non-empty bodies require application/json.';
                $schema = Util::getChild($parameter, OA\Schema::class);
                $schema->type = 'string';
                $schema->enum = ['1'];
                foreach ([403 => 'Invalid request protection header or origin.', 415 => 'Non-empty request body requires application/json.'] as $status => $description) {
                    $responses = \is_array($operation->responses) ? $operation->responses : [];
                    foreach ($responses as $response) {
                        if ((string) $response->response === (string) $status) {
                            continue 2;
                        }
                    }
                    $response = Util::getCollectionItem($operation, OA\Response::class, ['response' => (string) $status]);
                    $response->description = $description;
                    $media = Util::getCollectionItem($response, OA\MediaType::class, ['mediaType' => 'application/json']);
                    Util::getChild($media, OA\Schema::class)->ref = '#/components/schemas/ValidationErrorResponse';
                }
            }
        }
    }
}
