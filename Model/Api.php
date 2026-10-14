<?php
/**
 * Minimal client for the PayGate.to card API (control/convert.php, control/wallet.php) and the
 * crypto price endpoint used to value payouts sent in POL, ETH or BNB.
 */
namespace PayGate\InstantPayment\Model;

use Magento\Framework\HTTP\Client\CurlFactory;

class Api
{
    /**
     * @var CurlFactory
     */
    private $curlFactory;

    public function __construct(CurlFactory $curlFactory)
    {
        $this->curlFactory = $curlFactory;
    }

    /**
     * GET https://{host}/{path}?{query} and decode the JSON answer.
     *
     * @param string $host API host (already normalised)
     * @param string $path e.g. "control/wallet.php"
     * @param array $query unencoded values; encoded here (RFC 3986)
     * @return array|null null on a network error, a non-2xx status or a non-JSON answer
     */
    public function get($host, $path, array $query = [])
    {
        $url = 'https://' . $host . '/' . ltrim($path, '/');

        if ($query) {
            $url .= '?' . http_build_query(array_map('strval', $query), '', '&', PHP_QUERY_RFC3986);
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(30);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, 15);
            $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
            $curl->addHeader('Accept', 'application/json');
            $curl->addHeader('User-Agent', 'Magento-PayGate-Card/1.0');
            $curl->get($url);
            $status = (int) $curl->getStatus();
            $body = (string) $curl->getBody();
        } catch (\Exception $e) {
            return null;
        }

        if ($status < 200 || $status >= 300) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
