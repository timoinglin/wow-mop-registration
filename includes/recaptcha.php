<?php

$config = require __DIR__ . '/../config.php';

/**
 * Verifies the Google reCAPTCHA v2 response.
 * Returns true immediately if feature is disabled in config.
 *
 * @param string|null $recaptchaResponse The value of 'g-recaptcha-response' from POST.
 * @return bool True if verified (or feature disabled), false otherwise.
 */
function verifyRecaptcha(?string $recaptchaResponse): bool
{
    global $config;

    // Feature disabled → always pass
    if (empty($config['features']['recaptcha'])) {
        return true;
    }

    if (empty($recaptchaResponse)) {
        return false;
    }

    $verificationUrl = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret'   => $config['recaptcha']['secret_key'],
        'response' => $recaptchaResponse,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($verificationUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $responseJson === false) {
            error_log('reCAPTCHA verification request failed. HTTP Code: ' . $httpCode);
            return false;
        }
    } else {
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
                'timeout' => 5,
            ],
        ];
        $context = stream_context_create($options);
        $responseJson = @file_get_contents($verificationUrl, false, $context);

        if ($responseJson === false) {
            error_log('reCAPTCHA verification request failed using file_get_contents.');
            return false;
        }
    }

    $responseData = json_decode($responseJson);

    if (!$responseData) {
        error_log('reCAPTCHA JSON decoding failed. Response: ' . $responseJson);
        return false;
    }

    return $responseData->success === true;
}
