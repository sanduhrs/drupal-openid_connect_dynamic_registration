<?php

namespace Drupal\openid_connect_dynamic_registration\Controller;

use Drupal\Component\Utility\Random;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Language\Language;
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
    $random = new Random();
    $client_secret = $random->string(64);

    // @see https://openid.net/specs/openid-connect-registration-1_0.html#rfc.section.3.1
    $content = \Drupal::requestStack()->getCurrentRequest()->getContent();
    $request = json_decode($content);
    $values = [
      'langcode' => Language::LANGCODE_DEFAULT,
      'label' => $request->client_name,
      'third_party' => TRUE,
      'is_default' => FALSE,
      'secret' => $client_secret,
      'user_id' => 0,
      'redirect' => implode("\n", $request->redirect_uris),
    ];

    if (
      isset($request->logo_uri) &&
      /** @var $file \Drupal\file\FileInterface */
      $file = system_retrieve_file($request->logo_uri, 'public://consumer/', TRUE, FileSystem::EXISTS_RENAME)
    ) {
      $values['image'] = $file->id();
    }

    $consumer = Consumer::create($values);
    try {
      $consumer->save();
    }
    catch (\Exception $e) {
      // @see https://openid.net/specs/openid-connect-registration-1_0.html#rfc.section.3.3
      $response = [
        'error' => $e->getCode(),
        'error_description' => $e->getMessage(),
      ];
      return new JsonResponse($response, 400);
    }

    // @see https://openid.net/specs/openid-connect-registration-1_0.html#rfc.section.3.2
    $response = array_merge([
      'client_id' => $consumer->uuid(),
      'client_secret' => $client_secret,
    ], [
      'client_name' => $request->client_name,
    ]);

    if (isset($file)) {
      $response['logo_uri'] = $file->getFileUri();
    }
    return new JsonResponse($response, 201);
  }

}
