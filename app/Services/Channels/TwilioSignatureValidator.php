<?php

namespace App\Services\Channels;

use Illuminate\Http\Request;

final class TwilioSignatureValidator
{
    public function valid(Request $request, string $authToken): bool
    {
        $signature = $request->header('X-Twilio-Signature');

        if (! is_string($signature) || $signature === '' || $authToken === '') {
            return false;
        }

        $parameters = $request->request->all();
        ksort($parameters);
        $data = $request->fullUrl();

        foreach ($parameters as $key => $value) {
            if (is_scalar($value)) {
                $data .= $key.(string) $value;
            }
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($expected, $signature);
    }
}
