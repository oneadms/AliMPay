<?php

namespace AliMPay\Core;

use Alipay\OpenAPISDK\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use AliMPay\Utils\Logger;

class BillQuery
{
    private $alipayClient;
    private $logger;
    
    public function __construct(AlipayClient $alipayClient = null)
    {
        // Set Beijing timezone
        date_default_timezone_set('Asia/Shanghai');
        
        $this->alipayClient = $alipayClient ?: new AlipayClient();
        $this->logger = Logger::getInstance();
    }
    
    /**
     * Query account logs by date range
     * 
     * @param string $startTime Start time in format 'Y-m-d H:i:s'
     * @param string $endTime End time in format 'Y-m-d H:i:s'
     * @param int $pageNo Page number, default 1
     * @param int $pageSize Page size, default 2000
     * @return array Query result
     */
    public function queryBills(
        string $startTime,
        string $endTime,
        string $type = null,
        int $pageNo = 1,
        int $pageSize = 2000
    ): array {
        try {
            // Validate configuration
            if (!$this->alipayClient->validateConfig()) {
                throw new \Exception('Invalid Alipay configuration');
            }
            
            // Validate parameters
            $this->validateQueryParams($startTime, $endTime, $pageNo, $pageSize);
            
            $this->logger->info('Querying account logs', [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'page_no' => $pageNo,
                'page_size' => $pageSize
            ]);
            
            $result = $this->queryAccountLogsWithSignedRequest(
                $startTime,
                $endTime,
                $pageNo,
                $pageSize
            );

            $this->logger->info('Account log query successful', [
                'result_keys' => is_array($result) ? array_keys($result) : []
            ]);

            return $this->formatResult($result);
            
        } catch (ApiException $e) {
            $this->logger->error('API Exception occurred', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'response_body' => $e->getResponseBody(),
                'response_headers' => $e->getResponseHeaders()
            ]);

            throw new \Exception('查询失败: ' . $e->getMessage(), $e->getCode());
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $responseStatus = $response ? $response->getStatusCode() : null;
            $responseBody = $response ? (string)$response->getBody() : null;
            $responseHeaders = $response ? $response->getHeaders() : [];
            $decodedError = is_string($responseBody) ? json_decode($responseBody, true) : null;

            $this->logger->error('Signed account log request failed', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'response_status' => $responseStatus,
                'alipay_code' => is_array($decodedError) ? ($decodedError['code'] ?? null) : null,
                'alipay_message' => is_array($decodedError) ? ($decodedError['message'] ?? null) : null,
                'alipay_trace_id' => $responseHeaders['alipay-trace-id'][0] ?? null
            ]);

            $errorMessage = is_array($decodedError)
                ? (($decodedError['code'] ?? 'unknown') . ': ' . ($decodedError['message'] ?? ''))
                : $e->getMessage();
            throw new \Exception('查询失败: ' . $errorMessage, $e->getCode());
        } catch (\Exception $e) {
            $this->logger->error('Exception occurred', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Query account logs for today
     */
    public function queryTodayBills(): array
    {
        // Set Beijing timezone
        date_default_timezone_set('Asia/Shanghai');
        
        $today = date('Y-m-d');
        $startTime = $today . ' 00:00:00';
        $endTime = $today . ' 23:59:59';
        
        return $this->queryBills($startTime, $endTime);
    }
    
    /**
     * Query account logs for yesterday
     */
    public function queryYesterdayBills(): array
    {
        // Set Beijing timezone
        date_default_timezone_set('Asia/Shanghai');
        
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $startTime = $yesterday . ' 00:00:00';
        $endTime = $yesterday . ' 23:59:59';
        
        return $this->queryBills($startTime, $endTime);
    }
    
    /**
     * Query account logs for a specific date
     */
    public function queryBillsByDate(string $date): array
    {
        // Set Beijing timezone
        date_default_timezone_set('Asia/Shanghai');
        
        $startTime = $date . ' 00:00:00';
        $endTime = $date . ' 23:59:59';
        
        return $this->queryBills($startTime, $endTime);
    }
    
    private function queryAccountLogsWithSignedRequest(
        string $startTime,
        string $endTime,
        int $pageNo,
        int $pageSize
    ): array {
        $resourcePath = '/v3/alipay/data/bill/accountlog/query';
        $queryParams = [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'page_no' => (string)$pageNo,
            'page_size' => (string)$pageSize,
        ];
        $query = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $url = $resourcePath . '?' . $query;

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'alipay-request-id' => $this->alipayClient->getAlipayConfigUtil()->createUuid(),
        ];
        $this->alipayClient->getAlipayConfigUtil()->sign('GET', $url, '', $headers);

        $authorization = $headers['Authorization'] ?? '';
        $this->logger->info('Prepared signed Alipay account log request', [
            'url' => $resourcePath,
            'query_keys' => array_keys($queryParams),
            'has_authorization' => $authorization !== '',
            'authorization_has_sign' => strpos($authorization, ',sign=') !== false,
            'request_id' => $headers['alipay-request-id']
        ]);

        $client = new Client();
        $response = $client->request('GET', rtrim($this->alipayClient->getConfig()['server_url'], '/') . $url, [
            'headers' => $headers,
        ]);

        $body = (string)$response->getBody();
        $this->alipayClient->getAlipayConfigUtil()->verifyResponse($body, $response->getHeaders(), false);
        $body = $this->alipayClient->getAlipayConfigUtil()->decrypt($body);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \Exception('支付宝账单查询响应不是有效 JSON');
        }

        return $decoded;
    }

    private function validateQueryParams(
        string $startTime,
        string $endTime,
        int $pageNo,
        int $pageSize
    ): void {
        // Validate date format
        if (!$this->isValidDateTime($startTime) || !$this->isValidDateTime($endTime)) {
            throw new \InvalidArgumentException('Invalid date format, expected Y-m-d H:i:s');
        }
        
        // Validate page parameters
        if ($pageNo < 1) {
            throw new \InvalidArgumentException('Page number must be greater than 0');
        }
        
        if ($pageSize < 1 || $pageSize > 2000) {
            throw new \InvalidArgumentException('Page size must be between 1 and 2000');
        }
    }
    
    private function isValidDateTime(string $dateTime): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $dateTime);
        return $d && $d->format('Y-m-d H:i:s') === $dateTime;
    }
    
    private function formatResult($result): array
    {
        if (is_object($result)) {
            // Convert object to array
            $result = json_decode(json_encode($result), true);
        }
        
        // Set Beijing timezone for timestamp
        date_default_timezone_set('Asia/Shanghai');
        
        return [
            'success' => true,
            'data' => $result,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
} 