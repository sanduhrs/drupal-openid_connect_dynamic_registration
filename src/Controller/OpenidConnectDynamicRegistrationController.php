<?php

namespace Drupal\openid_connect_dynamic_registration\Controller;

use Drupal\Component\Utility\Random;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystem;
use Drupal\oauth2_server\Entity\Client;
use Laminas\Diactoros\Response\JsonResponse;

/**
 * Returns responses for OpenID Connect Dynamic Registration routes.
 *
 * @see https://openid.net/specs/openid-connect-registration-1_0.html
 */
class OpenidConnectDynamicRegistrationController extends ControllerBase {

  /**
   * Register an OpenID Connect client.
   *
   * @todo This needs a rate limit or flood protection of some kind.
   * @see https://www.drupal.org/project/rate_limits
   */
  public function register() : JsonResponse {
    // @see https://openid.net/specs/openid-connect-registration-1_0.html#rfc.section.3.1
    $content = \Drupal::requestStack()->getCurrentRequest()->getContent();
    $request = json_decode($content);

    // @todo Check all uris are valid.
    if (!isset($request->redirect_uris)) {
      // @see https://openid.net/specs/openid-connect-registration-1_0.html#rfc.section.3.3
      $response = [
        'error' => 'invalid_redirect_uri',
        'error_description' => 'One or more redirect_uri values are invalid',
      ];
      return new JsonResponse($response, 400);
    }

    $values = [
      'server_id' => 'iam',
      'client_name' => $request->client_name ?? (new Random)->name(36),
      'name' => $request->client_name ?? (new Random)->name(36),
      'redirect_uri' => isset($request->redirect_uris) ? implode("\n", $request->redirect_uris) : '',
      'logo_uri' => $request->logo_uri ?? '',
      'client_uri' => $request->client_uri ?? '',
      'policy_uri' => $request->policy_uri ?? '',
      'tos_uri' => $request->tos_uri ?? '',
    ];

    /** @var \Drupal\Core\Password\PasswordInterface $password */
    $password = \Drupal::service('password');
    $values['client_id'] = strtolower((new Random)->name(32));
    $unhashed_client_secret = (new Random)->name(64);
    $values['client_secret'] = $password->hash($unhashed_client_secret);

    if (
      isset($request->logo_uri) &&
      /** @var $file \Drupal\file\FileInterface */
      $file = system_retrieve_file($request->logo_uri, 'public://consumer/', TRUE, FileSystem::EXISTS_RENAME)
    ) {
      $values['image'] = $file->id();
    }

    $client = Client::create($values);
    try {
      $client->save();
    }
    catch (\Exception $e) {
      // @see https://openid.net/specs/openid-connect-registration-1_0.html#rfc.section.3.3
      $response = [
        'error' => $e->getCode(),
        'error_description' => $e->getMessage(),
      ];
      return new JsonResponse($response, 400);
    }

    // @todo Add all saved field values.
    // @see https://openid.net/specs/openid-connect-registration-1_0.html#rfc.section.3.2
    $response = array_merge([
      'client_id' => $client->client_id,
      'client_secret' => $unhashed_client_secret,
      'client_name' => $client->client_name,
    ]);

    if (isset($file)) {
      $response['logo_uri'] = $file->getFileUri();
    }
    return new JsonResponse($response, 201);
  }

}
