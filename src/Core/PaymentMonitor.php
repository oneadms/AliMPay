<?php

namespace AliMPay\Core;

use AliMPay\Core\BillQuery;
use AliMPay\Utils\Logger;
use Medoo\Medoo;

class PaymentMonitor
{
    private $billQuery;
    private $logger;
    private $db;
    private $codepay_config;
    
    public function __construct(BillQuery $billQuery, Medoo $db, array $codepay_config)
    {
        // Set Beijing timezone
        date_default_timezone_set('Asia/Shanghai');
        
        $this->billQuery = $billQuery;
        $this->db = $db;
        $this->codepay_config = $codepay_config;
        $this->logger = Logger::getInstance();
    }
    
    private function loadConfig(): array
    {
        $configPath = __DIR__ . '/../../config/alipay.php';
        return file_exists($configPath) ? require $configPath : [];
    }
    
    /**
     * Monitor payment status (centered on order time)
     * 
     * @param string $orderNo Order number (memo)
     * @param float $expectedAmount Expected amount
     * @param string|null $orderTime 下单时间（格式 Y-m-d H:i:s，默认当前时间）
     * @param int $hoursRange 查询范围（前后各多少小时）
     * @return array Payment result
     */
    public function monitorPayment(string $orderNo, float $expectedAmount, string $orderTime = null, int $hoursRange = 12): array
    {
        $startTime = time();
        $this->logger->info('Starting payment monitoring', [
            'order_no' => $orderNo,
            'expected_amount' => $expectedAmount,
            'max_wait_time' => $this->maxWaitTime,
            'order_time' => $orderTime,
            'hours_range' => $hoursRange
        ]);
        
        echo "开始监控支付状态...\n";
        echo "订单号: {$orderNo}\n";
        echo "期望金额: {$expectedAmount}\n";
        echo "最大等待时间: {$this->maxWaitTime}秒\n";
        echo "查询范围: 下单时间前后{$hoursRange}小时\n\n";
        
        // 计算查询时间区间
        $orderTimestamp = $orderTime ? strtotime($orderTime) : time();
        $queryStart = date('Y-m-d H:i:s', $orderTimestamp - $hoursRange * 3600);
        $queryEnd   = date('Y-m-d H:i:s', $orderTimestamp + $hoursRange * 3600);
        
        while (true) {
            $currentTime = time();
            $elapsed = $currentTime - $startTime;
            
            if ($elapsed >= $this->maxWaitTime) {
                $this->logger->warning('Payment monitoring timeout', [
                    'order_no' => $orderNo,
                    'elapsed_time' => $elapsed
                ]);
                
                return [
                    'success' => false,
                    'status' => 'timeout',
                    'message' => '支付监控超时',
                    'elapsed_time' => $elapsed
                ];
            }
            
            try {
                // 查询以下单时间为中心的账单
                $result = $this->billQuery->queryBills($queryStart, $queryEnd, null, 1, 100);
                
                if ($result['success']) {
                    $payment = $this->findPaymentByMemo($result['data'], $orderNo, $expectedAmount);
                    
                    if ($payment) {
                        $this->logger->info('Payment found', [
                            'order_no' => $orderNo,
                            'payment_data' => $payment,
                            'elapsed_time' => $elapsed
                        ]);
                        
                        echo "✓ 支付成功！\n";
                        echo "订单号: {$orderNo}\n";
                        echo "实际金额: {$payment['amount']}\n";
                        echo "支付时间: {$payment['trans_dt']}\n";
                        echo "支付状态: {$payment['status']}\n";
                        
                        return [
                            'success' => true,
                            'status' => 'paid',
                            'message' => '支付成功',
                            'payment_data' => $payment,
                            'elapsed_time' => $elapsed
                        ];
                    }
                }
                
                // Print progress
                $remainingTime = $this->maxWaitTime - $elapsed;
                echo "⏳ 等待支付... 剩余时间: {$remainingTime}秒 (查询区间: {$queryStart} ~ {$queryEnd})\r";
                
                sleep($this->checkInterval);
                
            } catch (\Exception $e) {
                $this->logger->error('Error during payment monitoring', [
                    'order_no' => $orderNo,
                    'error' => $e->getMessage(),
                    'elapsed_time' => $elapsed
                ]);
                
                echo "监控过程中发生错误: {$e->getMessage()}\n";
                sleep($this->checkInterval);
            }
        }
    }
    
    /**
     * Query recent bills
     * 
     * @param int $hoursBack How many hours back to query (default 24 hours)
     * @return array
     */
    private function queryRecentBills(int $hoursBack = 24): array
    {
        // Set Beijing timezone
        date_default_timezone_set('Asia/Shanghai');
        
        // Get current time and subtract 5 minutes for both start and end time
        $endTime = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $startTime = date('Y-m-d H:i:s', strtotime("-{$hoursBack} hours -5 minutes")); // Query last N hours minus 5 minutes
        
        $this->logger->info('Querying recent bills with Beijing time', [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'hours_back' => $hoursBack,
            'timezone' => date_default_timezone_get()
        ]);
        
        return $this->billQuery->queryBills($startTime, $endTime, null, 1, 100);
    }
    
    /**
     * Query bills with custom time range
     * 
     * @param string $startTime
     * @param string $endTime
     * @return array
     */
    public function queryBillsInTimeRange(string $startTime, string $endTime): array
    {
        $this->logger->info('Querying bills in custom time range', [
            'start_time' => $startTime,
            'end_time' => $endTime
        ]);
        
        return $this->billQuery->queryBills($startTime, $endTime, null, 1, 100);
    }
    
    /**
     * Find payment by memo and amount
     * 
     * @param array $billData
     * @param string $orderNo
     * @param float $expectedAmount
     * @return array|null
     */
    private function findPaymentByMemo(array $billData, string $orderNo, float $expectedAmount): ?array
    {
        // 检查数据结构，支持多种格式
        $bills = [];
        if (isset($billData['detail_list']) && is_array($billData['detail_list'])) {
            $bills = $billData['detail_list'];
        } elseif (isset($billData['accountLogList']) && is_array($billData['accountLogList'])) {
            $bills = $billData['accountLogList'];
        } elseif (is_array($billData) && isset($billData[0])) {
            // 如果billData直接是数组格式
            $bills = $billData;
        } else {
            $this->logger->warning('Bill data is missing or not in expected format.', ['order_no' => $orderNo]);
            return null;
        }
        
        foreach ($bills as $bill) {
            // 支持多种字段名称格式
            $remark = $bill['trans_memo'] ?? ($bill['memo'] ?? ($bill['remark'] ?? ''));
            $amount = $bill['trans_amount'] ?? ($bill['amount'] ?? 0);
            $direction = $bill['direction'] ?? '';
            
            $logContext = [
                'target_order_no' => $orderNo,
                'expected_amount' => $expectedAmount,
                'bill_memo' => $remark,
                'bill_amount' => $amount,
                'bill_direction' => $direction
            ];

            // Check if it's an income transaction
            if (!empty($direction) && $direction !== '收入') {
                continue; // Skip non-income records
            }

            // The remark from Alipay should match the order number we are looking for.
            // Using trim() to avoid issues with leading/trailing whitespace.
            if (trim($remark) === $orderNo) {
                // Check if amount matches
                if (abs(floatval($amount) - $expectedAmount) < 0.01) {
                    $this->logger->info('Payment match found.', $logContext);
                    return [
                        'account_log_id' => $bill['account_log_id'] ?? '',
                        'alipay_order_no' => $bill['alipay_order_no'] ?? ($bill['alipayOrderNo'] ?? ''),
                        'amount' => $amount,
                        'trans_dt' => $bill['trans_dt'] ?? ($bill['transDate'] ?? ''),
                        'status' => $direction,
                        'trans_memo' => $remark,
                        'other_account' => $bill['other_account'] ?? '',
                        'type' => $bill['type'] ?? ''
                    ];
                } else {
                    $this->logger->debug('Order ID matched, but amount did not.', $logContext);
                }
            }
        }
        
        $this->logger->info('No matching payment found in the provided bill data.', ['order_no' => $orderNo]);
        return null;
    }
    
    /**
     * 手动搜索支付记录（下单时间为中心，前后N小时）
     * @param string $orderNo
     * @param float $expectedAmount
     * @param string|null $orderTime
     * @param int $hoursRange
     * @return array
     */
    public function searchPayment(string $orderNo, float $expectedAmount, string $orderTime = null, int $hoursRange = 12): array
    {
        $this->logger->info('Manually searching for payment', [
            'order_no' => $orderNo,
            'expected_amount' => $expectedAmount,
            'order_time' => $orderTime,
            'hours_range' => $hoursRange
        ]);
        
        try {
            $orderTimestamp = $orderTime ? strtotime($orderTime) : time();
            $queryStart = date('Y-m-d H:i:s', $orderTimestamp - $hoursRange * 3600);
            $queryEnd   = date('Y-m-d H:i:s', $orderTimestamp + $hoursRange * 3600);
            
            $this->logger->info('Executing bill query with time range', [
                'order_no' => $orderNo,
                'query_start' => $queryStart,
                'query_end' => $queryEnd
            ]);
            
            $result = $this->billQuery->queryBills($queryStart, $queryEnd, null, 1, 200);
            
            $detailList = $result['data']['detail_list'] ?? $result['data']['accountLogList'] ?? [];
            if ($result['success'] && !empty($detailList)) {
                $this->logger->info('Bills query successful, found ' . count($detailList) . ' records.', ['order_no' => $orderNo]);
                $payment = $this->findPaymentByMemo($result['data'], $orderNo, $expectedAmount);

                if ($payment) {
                    $this->logger->info('Payment found in manual search', [
                        'order_no' => $orderNo,
                        'payment_data' => $payment
                    ]);
                    
                    return [
                        'success' => true,
                        'status' => 'found',
                        'message' => '找到支付记录',
                        'payment_data' => $payment,
                        'search_range' => $queryStart . ' ~ ' . $queryEnd
                    ];
                } else {
                    $this->logger->info('Payment not found in manual search', [
                        'order_no' => $orderNo,
                        'expected_amount' => $expectedAmount,
                        'search_range' => $queryStart . ' ~ ' . $queryEnd,
                        'total_records' => count($detailList)
                    ]);

                    return [
                        'success' => false,
                        'status' => 'not_found',
                        'message' => '未找到匹配的支付记录',
                        'search_range' => $queryStart . ' ~ ' . $queryEnd,
                        'total_records_checked' => count($detailList)
                    ];
                }
            } else {
                throw new \Exception('查询账单失败');
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error during manual payment search', [
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'status' => 'error',
                'message' => '搜索过程中发生错误: ' . $e->getMessage(),
                'search_range' => $queryStart . ' ~ ' . $queryEnd
            ];
        }
    }
    
    /**
     * Set monitoring parameters
     * 
     * @param int $maxWaitTime Maximum wait time in seconds
     * @param int $checkInterval Check interval in seconds
     * @param int $queryHoursBack Query hours back
     */
    public function setMonitoringParams(int $maxWaitTime, int $checkInterval, int $queryHoursBack = null): void
    {
        $this->maxWaitTime = $maxWaitTime;
        $this->checkInterval = $checkInterval;
        if ($queryHoursBack !== null) {
            $this->queryHoursBack = $queryHoursBack;
        }
    }
    
    /**
     * Set query time range
     * 
     * @param int $hoursBack How many hours back to query
     */
    public function setQueryHoursBack(int $hoursBack): void
    {
        $this->queryHoursBack = $hoursBack;
    }
    
    /**
     * Get current monitoring parameters
     * 
     * @return array
     */
    public function getMonitoringParams(): array
    {
        return [
            'max_wait_time' => $this->maxWaitTime,
            'check_interval' => $this->checkInterval,
            'query_hours_back' => $this->queryHoursBack,
        ];
    }

    /**
     * Run a single monitoring cycle to check and update pending orders.
     * This is designed to be triggered by a cron job or a web request.
     */
    public function runMonitoringCycle(): void
    {
        $minutes = $this->codepay_config['query_minutes_back'] ?? 30;
        $this->logger->info("Starting payment monitoring cycle for the last {$minutes} minutes...");

        // 计算时间范围
        $endTime = date('Y-m-d H:i:s');
        $startTime = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
        
        $this->logger->info("Querying bills from {$startTime} to {$endTime}");

        try {
            $result = $this->billQuery->queryBills($startTime, $endTime);

            $this->logger->info('Bill query response summary.', [
                'success' => $result['success'] ?? null,
                'data_keys' => isset($result['data']) && is_array($result['data']) ? array_keys($result['data']) : [],
                'raw_count_detail_list' => isset($result['data']['detail_list']) && is_array($result['data']['detail_list']) ? count($result['data']['detail_list']) : null,
                'raw_count_account_log_list' => isset($result['data']['accountLogList']) && is_array($result['data']['accountLogList']) ? count($result['data']['accountLogList']) : null,
                'message' => $result['message'] ?? null
            ]);

            if (!$result['success']) {
                $this->logger->error("Failed to query bills.", ['response' => $result['message'] ?? 'Alipay API returned an error.']);
                return;
            }
            
            $bills = $this->extractBillsFromResult($result['data']);

            if (empty($bills)) {
                $this->logger->info("No recent payment bills found in the last {$minutes} minutes.");
                return;
            }

            $this->logger->info("Found " . count($bills) . " bill(s) to process.");
            
            // 检查是否启用经营码收款模式
            $config = $this->loadConfig();
            $businessQrMode = $config['payment']['business_qr_mode']['enabled'] ?? false;
            
            if ($businessQrMode) {
                $this->logger->info('Routing bills to business QR matcher.', [
                    'bill_count' => count($bills),
                    'match_tolerance' => $config['payment']['business_qr_mode']['match_tolerance'] ?? null,
                    'order_timeout' => $config['payment']['order_timeout'] ?? null,
                    'query_minutes_back' => $config['payment']['query_minutes_back'] ?? null
                ]);
                $this->processBillsForBusinessQrMode($bills);
            } else {
                $this->processBillsForTraditionalMode($bills);
            }

        } catch (\Exception $e) {
            $this->logger->error("Error during monitoring cycle: " . $e->getMessage());
        } finally {
            $this->retryPendingNotifications();
            $this->cleanupExpiredOrders();
        }

        $this->logger->info("Payment monitoring cycle finished.");
    }
    
    private function extractBillsFromResult(array $data): array
    {
        $bills = [];
        $source = 'none';
        if (isset($data['detail_list']) && is_array($data['detail_list'])) {
            $bills = $data['detail_list'];
            $source = 'detail_list';
        } elseif (isset($data['accountLogList']) && is_array($data['accountLogList'])) {
            $bills = $data['accountLogList'];
            $source = 'accountLogList';
        } elseif (is_array($data) && isset($data[0])) {
            $bills = $data;
            $source = 'indexed_array';
        }

        $this->logger->info('Extracting bills from Alipay result.', [
            'source' => $source,
            'raw_count' => count($bills),
            'top_level_keys' => array_keys($data)
        ]);

        if (empty($bills)) {
            return [];
        }

        $formattedBills = [];
        foreach ($bills as $index => $bill) {
            $direction = $bill['direction'] ?? '';
            $rawAmount = $bill['trans_amount'] ?? ($bill['amount'] ?? 0);
            $rawTime = $bill['trans_dt'] ?? ($bill['transDate'] ?? '');
            $rawTradeNo = $bill['alipay_order_no'] ?? ($bill['alipayOrderNo'] ?? ($bill['tradeNo'] ?? ''));
            $rawRemark = $bill['trans_memo'] ?? ($bill['memo'] ?? ($bill['remark'] ?? ''));

            $this->logger->info('Raw bill item received.', [
                'index' => $index,
                'direction' => $direction,
                'amount' => $rawAmount,
                'trans_time' => $rawTime,
                'trade_no' => $rawTradeNo,
                'remark' => $rawRemark,
                'type' => $bill['type'] ?? '',
                'keys' => array_keys($bill)
            ]);

            if (!empty($direction) && $direction !== '收入') {
                $this->logger->info('Skipping non-income bill.', [
                    'index' => $index,
                    'direction' => $direction,
                    'amount' => $rawAmount,
                    'trans_time' => $rawTime
                ]);
                continue;
            }

            $formattedBills[] = [
                'tradeNo' => $rawTradeNo,
                'amount' => $rawAmount,
                'remark' => $rawRemark,
                'transDate' => $rawTime,
                'balance' => $bill['balance'] ?? 0,
                'type' => $bill['type'] ?? ''
            ];
        }

        $this->logger->info('Formatted income bills.', [
            'formatted_count' => count($formattedBills)
        ]);

        return $formattedBills;
    }

    private function processBillsForBusinessQrMode(array $bills): void
    {
        $config = $this->loadConfig();
        $tolerance = $config['payment']['business_qr_mode']['match_tolerance'] ?? 300;
        $pendingOrders = $this->db->select('codepay_orders', [
            'id',
            'out_trade_no',
            'pid',
            'price',
            'payment_amount',
            'status',
            'add_time'
        ], [
            'status' => 0,
            'ORDER' => ['add_time' => 'ASC'],
            'LIMIT' => 20
        ]);

        $this->logger->info('Business QR mode enabled. Using amount-based matching.', [
            'bill_count' => count($bills),
            'pending_order_count_sample' => count($pendingOrders),
            'match_tolerance' => $tolerance,
            'pending_orders_sample' => $pendingOrders
        ]);

        foreach ($bills as $bill) {
            $billAmount = (float)$bill['amount'];
            $billTime = strtotime($bill['transDate']);

            $this->logger->info('Processing business QR bill.', [
                'trade_no' => $bill['tradeNo'],
                'raw_amount' => $bill['amount'],
                'amount_float' => $billAmount,
                'raw_time' => $bill['transDate'],
                'parsed_time' => $billTime ? date('Y-m-d H:i:s', $billTime) : null,
                'remark' => $bill['remark'],
                'type' => $bill['type']
            ]);

            if ($billTime === false) {
                $this->logger->warning('Skipping bill because transaction time could not be parsed.', [
                    'trade_no' => $bill['tradeNo'],
                    'raw_time' => $bill['transDate'],
                    'amount' => $bill['amount']
                ]);
                continue;
            }

            $amountCandidates = $this->db->select('codepay_orders', [
                'id',
                'out_trade_no',
                'pid',
                'price',
                'payment_amount',
                'status',
                'add_time'
            ], [
                'payment_amount' => $billAmount,
                'status' => 0,
                'ORDER' => ['add_time' => 'ASC'],
                'LIMIT' => 10
            ]);

            $this->logger->info('Business QR amount candidate lookup.', [
                'bill_amount' => $billAmount,
                'candidate_count' => count($amountCandidates),
                'candidates' => $amountCandidates
            ]);

            if (empty($amountCandidates)) {
                $this->logger->info('No pending order found for bill amount. Skipping bill.', [
                    'bill_amount' => $billAmount,
                    'trade_no' => $bill['tradeNo']
                ]);
                continue;
            }

            foreach ($amountCandidates as $order) {
                $orderTime = strtotime($order['add_time']);
                $timeDiff = $billTime - $orderTime;

                $this->logger->info('Checking business QR candidate time window.', [
                    'order_id' => $order['id'],
                    'out_trade_no' => $order['out_trade_no'],
                    'order_amount' => $order['payment_amount'],
                    'order_time' => $order['add_time'],
                    'bill_time' => $bill['transDate'],
                    'time_diff' => $timeDiff,
                    'tolerance' => $tolerance
                ]);

                if ($billTime < $orderTime || $timeDiff > $tolerance) {
                    $this->logger->warning('Business QR candidate rejected by time tolerance.', [
                        'order_id' => $order['id'],
                        'out_trade_no' => $order['out_trade_no'],
                        'order_time' => $order['add_time'],
                        'bill_time' => $bill['transDate'],
                        'time_diff' => $timeDiff,
                        'tolerance' => $tolerance
                    ]);
                    continue;
                }

                $this->logger->info("Payment match found for order {$order['id']}. Updating status to paid.", [
                    'out_trade_no' => $order['out_trade_no'],
                    'bill_trade_no' => $bill['tradeNo'],
                    'bill_amount' => $billAmount,
                    'time_diff' => $timeDiff
                ]);

                $updated = $this->db->update('codepay_orders', [
                    'status' => 1,
                    'pay_time' => date('Y-m-d H:i:s')
                ], [
                    'id' => $order['id'],
                    'status' => 0
                ]);

                if ($updated->rowCount() <= 0) {
                    $this->logger->warning('Failed to update order status, it might have been updated by another process.', [
                        'order_id' => $order['id']
                    ]);
                    return;
                }

                $paidOrder = $this->db->get('codepay_orders', '*', ['id' => $order['id']]);
                if (!$paidOrder) {
                    $this->logger->error('Failed to reload paid order for merchant notification.', [
                        'order_id' => $order['id'],
                        'out_trade_no' => $order['out_trade_no']
                    ]);
                    return;
                }

                $this->logger->info('Business QR order marked as paid. Sending merchant notification.', [
                    'order_id' => $paidOrder['id'],
                    'out_trade_no' => $paidOrder['out_trade_no'],
                    'has_notify_url' => !empty($paidOrder['notify_url'])
                ]);
                $this->notifyUser($paidOrder);
                $this->logger->info("Order {$order['id']} successfully marked as paid and notification attempted.");

                return;
            }
        }
    }

    private function processBillsForTraditionalMode(array $bills): void
    {
        $this->logger->info("Traditional mode enabled. Using memo-based matching.");

        foreach ($bills as $bill) {
            $this->logger->info("Processing bill: Trade No={$bill['tradeNo']}, Amount={$bill['amount']}, Remark={$bill['remark']}");
            $remark = $bill['remark'];

            if (empty($remark)) {
                $this->logger->info("Skipping bill with empty remark.", ['trade_no' => $bill['tradeNo']]);
                continue;
            }

            $out_trade_no = trim($remark);
            
            $order = $this->db->get('codepay_orders', '*', [
                'out_trade_no' => $out_trade_no,
                'status' => 0
            ]);

            if ($order) {
                if (abs((float)$order['price'] - (float)$bill['amount']) < 0.01) {
                    $this->logger->info("Payment match found for order {$order['id']}. Updating status to paid.", [
                        'out_trade_no' => $order['out_trade_no']
                    ]);
                    $this->db->update('codepay_orders', [
                        'status' => 1,
                        'pay_time' => date('Y-m-d H:i:s')
                    ], ['id' => $order['id']]);
                    $this->notifyUser($order);
                } else {
                    $this->logger->warning("Amount mismatch for order {$order['id']}.", [
                        'out_trade_no' => $order['out_trade_no'],
                        'expected_amount' => $order['price'],
                        'bill_amount' => $bill['amount']
                    ]);
                }
            }
        }
    }

    private function notifyUser($order): bool
    {
        if (empty($order['notify_url'])) {
            $this->logger->log("No notify_url configured for order {$order['id']}. Skipping notification.");
            return false;
        }

        $codePay = new \AliMPay\Core\CodePay();
        $success = $codePay->sendNotification($order);
        $this->db->update('codepay_orders', [
            'notify_status' => $success ? 1 : 0,
            'notify_time' => date('Y-m-d H:i:s'),
            'notify_count[+]' => 1
        ], ['id' => $order['id']]);

        if ($success) {
            $this->logger->log("Merchant notification successful for order {$order['id']}.");
        } else {
            $this->logger->log("Merchant notification failed for order {$order['id']}.");
        }

        return $success;
    }

    private function retryPendingNotifications(): void
    {
        $orders = $this->db->select('codepay_orders', '*', [
            'status' => 1,
            'notify_status' => 0,
            'notify_count[<]' => 10,
            'notify_url[!]' => '',
            'ORDER' => ['pay_time' => 'ASC'],
            'LIMIT' => 20
        ]);

        if (empty($orders)) {
            return;
        }

        $this->logger->info('Retrying pending merchant notifications.', ['count' => count($orders)]);

        foreach ($orders as $order) {
            $this->notifyUser($order);
        }
    }

    /**
     * 清理过期订单
     * 删除超过指定时间的待支付订单
     */
    private function cleanupExpiredOrders(): void
    {
        $config = $this->loadConfig();
        $autoCleanup = $config['payment']['auto_cleanup'] ?? true;
        
        if (!$autoCleanup) {
            return;
        }
        
        $orderTimeoutSeconds = $config['payment']['order_timeout'] ?? 300;
        $queryWindowSeconds = (int)(($config['payment']['query_minutes_back'] ?? 30) * 60);
        $matchToleranceSeconds = $config['payment']['business_qr_mode']['match_tolerance'] ?? 300;
        $timeoutSeconds = max($orderTimeoutSeconds, $queryWindowSeconds + $matchToleranceSeconds);
        $expiredTime = date('Y-m-d H:i:s', time() - $timeoutSeconds);
        
        try {
            // 查询过期的待支付订单
            $expiredOrders = $this->db->select('codepay_orders', ['id', 'out_trade_no', 'add_time'], [
                'status' => 0,  // 待支付状态
                'add_time[<]' => $expiredTime
            ]);
            
            if (empty($expiredOrders)) {
                $this->logger->debug('No expired orders found for cleanup.');
                return;
            }
            
            $this->logger->info('Found expired orders for cleanup.', [
                'count' => count($expiredOrders),
                'expired_before' => $expiredTime,
                'timeout_seconds' => $timeoutSeconds,
                'order_timeout_seconds' => $orderTimeoutSeconds,
                'query_window_seconds' => $queryWindowSeconds,
                'match_tolerance_seconds' => $matchToleranceSeconds
            ]);
            
            // 删除过期订单
            $deletedCount = $this->db->delete('codepay_orders', [
                'status' => 0,
                'add_time[<]' => $expiredTime
            ]);
            
            $this->logger->info('Expired orders cleanup completed.', [
                'deleted_count' => $deletedCount,
                'expired_time_threshold' => $expiredTime
            ]);
            
            // 记录被删除的订单详情（用于调试）
            foreach ($expiredOrders as $order) {
                $this->logger->debug('Expired order deleted.', [
                    'order_id' => $order['id'],
                    'out_trade_no' => $order['out_trade_no'],
                    'created_time' => $order['add_time'],
                    'expired_seconds' => time() - strtotime($order['add_time'])
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup expired orders.', [
                'error' => $e->getMessage(),
                'expired_time' => $expiredTime
            ]);
    }
    }


}