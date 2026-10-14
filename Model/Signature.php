<?php
/**
 * PayGate.to callback signature verification.
 *
 * Every callback PayGate sends is signed with RSA-2048 / SHA-256 (PKCS#1 v1.5):
 *   - X-PayGate-Signature: base64 signature
 *   - X-PayGate-Key-Id:    id of the published key that signed it (default "v1")
 *   - signed content:      the exact URL PayGate requested
 * See https://paygate.to/docs/payment-gateway-api/#callback-event
 *
 * Nothing is rebuilt from parsed parameters and the Host header is never trusted: the signed string
 * is the callback URL this module registered (up to its "?") plus the raw query string exactly as it
 * arrived, taken from REQUEST_URI so a web-server rewrite of QUERY_STRING cannot break it.
 */
namespace PayGate\InstantPayment\Model;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

class Signature
{
    /**
     * @var ModuleDirReader
     */
    private $moduleDirReader;

    public function __construct(ModuleDirReader $moduleDirReader)
    {
        $this->moduleDirReader = $moduleDirReader;
    }

    /**
     * @param HttpRequest $request
     * @param string $registeredCallbackUrl callback URL given to PayGate for this order
     * @return array ['verified' => bool, 'reason' => string]
     */
    public function verify(HttpRequest $request, $registeredCallbackUrl)
    {
        $signature = trim((string) $request->getHeader('X-PayGate-Signature'));

        if ($signature === '') {
            return ['verified' => false, 'reason' => 'signature_absent'];
        }

        if (!function_exists('openssl_verify')) {
            return ['verified' => false, 'reason' => 'openssl_unavailable'];
        }

        $keyId = trim((string) $request->getHeader('X-PayGate-Key-Id'));

        if ($keyId === '') {
            $keyId = 'v1';
        }

        // Never let a header reach the filesystem unchecked.
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            return ['verified' => false, 'reason' => 'unknown_key_id'];
        }

        $pemFile = $this->moduleDirReader->getModuleDir('', 'PayGate_InstantPayment') . '/keys/paygate_callback_public_' . $keyId . '.pem';

        if (!is_file($pemFile) || !is_readable($pemFile)) {
            return ['verified' => false, 'reason' => 'unknown_key_id'];
        }

        $publicKey = openssl_pkey_get_public((string) file_get_contents($pemFile));

        if ($publicKey === false) {
            return ['verified' => false, 'reason' => 'bad_public_key'];
        }

        $rawSignature = base64_decode($signature, true);

        if ($rawSignature === false || $rawSignature === '') {
            return ['verified' => false, 'reason' => 'signature_not_base64'];
        }

        $registeredCallbackUrl = (string) $registeredCallbackUrl;
        $pos = strpos($registeredCallbackUrl, '?');
        $signed = $pos === false ? $registeredCallbackUrl : substr($registeredCallbackUrl, 0, $pos);

        if (!preg_match('#^https?://[^/?\#\s]+#i', $signed)) {
            return ['verified' => false, 'reason' => 'no_registered_callback'];
        }

        $query = self::rawQueryString($request);

        if ($query !== '') {
            $signed .= '?' . $query;
        }

        $result = openssl_verify($signed, $rawSignature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($result === 1) {
            return ['verified' => true, 'reason' => ''];
        }

        return ['verified' => false, 'reason' => $result === -1 ? 'openssl_error' : 'signature_mismatch'];
    }

    /**
     * The raw query string of the request, byte for byte.
     *
     * @param HttpRequest $request
     * @return string
     */
    public static function rawQueryString(HttpRequest $request)
    {
        $uri = (string) $request->getServer('REQUEST_URI');
        $pos = strpos($uri, '?');

        if ($pos !== false) {
            $query = substr($uri, $pos + 1);
            $hash = strpos($query, '#');

            return $hash === false ? $query : substr($query, 0, $hash);
        }

        return (string) $request->getServer('QUERY_STRING');
    }

    /**
     * Parameters of the callback, parsed from the same raw query string the signature covers.
     *
     * @param HttpRequest $request
     * @return array
     */
    public static function callbackParams(HttpRequest $request)
    {
        $params = [];
        parse_str(self::rawQueryString($request), $params);

        return $params;
    }
}
