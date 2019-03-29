<?php
/**
 * Commerce Reports plugin for Craft CMS 3.x
 *
 * Plugin to run specific Commerce reports
 *
 * @link      https://milesherndon.com
 * @copyright Copyright (c) 2019 MilesHerndon
 */

namespace milesherndon\commercereports\services;

use milesherndon\commercereports\CommerceReports;
use milesherndon\commercereports\helpers\ReportDateTimeHelper;
use milesherndon\commercereports\helpers\ReportFileHelper;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;

/**
 * @author    MilesHerndon
 * @package   CommerceReports
 * @since     1.0.5
 */
class OrderService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Get orders by customer
     *
     * @param $request
     * @return string
     */
    public function getOrdersByCustomer($request)
    {
        $customerEmailPreformatted = $request['customer'];
        $customerEmail = str_replace('\@', '@', $customerEmailPreformatted);

        $orders = $this->getOrdersByDate($request);

        $customerOrders = [];

        foreach ($orders as $order) {
            if ($order->email == $customerEmail) {
                array_push($customerOrders, $order);
            }
        }

        $nameTemplate = $customerEmail;
        $filePath = ReportFileHelper::getStoragePath('commerce-reports-customer');
        $csvFileName = 'customer_'.$nameTemplate.'.csv';
        $fileName = $filePath . '/' . $csvFileName;

        $fp = fopen($fileName, 'w');

        fputcsv($fp, [
            'Order #',
            'Date Ordered',
            'Customer',
            'Purchase Total',
            'Status'
        ]);

        foreach ($customerOrders as $order) {
            $address = $order->shippingAddress;
            $row = [
                'Order #' => (string)$order->shortNumber,
                'Date Ordered' => $order->dateOrdered->format('Y-m-d'),
                'Customer' => $address->firstName . ' ' . $address->lastName,
                'Purchase Total' => $order->itemSubtotal,
                'Status' => $order->status,
            ];

            fputcsv($fp, $row);
        }
        fclose($fp);

        return $fileName;
    }

    /**
     * Gets orders between a specified date
     *
     * @param $request
     * @return craft\commerce\elements\Order
     */
    public function getOrdersByDate($request)
    {
        if (empty($request)) {
            return false;
        }

        $dates = ReportDateTimeHelper::formatTimes($request, 'c', true);

        return Order::find()
            ->isCompleted(true)
            ->orderBy('dateOrdered asc')
            ->dateOrdered(["and", ">=".$dates['start'], "<".$dates['end']])
            ->all();
    }

    // // NOTE: Part of batchTransations
    public function getOrdersWithDetails($request, $filepath)
    {
        if (empty($request)) {
            return false;
        }

        $orders = $this->getOrdersByDate($request);

        $fileName = $filepath . '/' . uniqid() . '.csv';

        $fp = fopen($fileName, 'w');

        fputcsv($fp, [
            'Order number',
            'Status',
            'Product total',
            'Tax total',
            'Shipping total',
            'Wholesale total',
            'total paid',
            'date ordered',
            'date paid'
        ]);

        foreach ($orders as $order) {
            $row = [
                'Order number' => (string)$order->getShortNumber(),
                'Status' => $order->getOrderStatus(),
                'Product total' => number_format(floatval($order->getItemTotal() - $order->getAdjustmentsTotalByType("tax")), 2, '.', ''),
                'Tax total' => number_format((float)$order->getAdjustmentsTotalByType("tax"), 2, '.', ''),
                'Shipping total' => number_format((float)$order->getAdjustmentsTotalByType("shipping"), 2, '.', ''),
                'Wholesale total' => floatval(CommerceReports::$plugin->inventoryService->totalProductWholesale($order->getLineItems())),
                'total paid' => number_format(floatval($order->getItemTotal() + $order->getAdjustmentsTotalByType("shipping")), 2, '.', ''),
                'date ordered' => $order->dateOrdered->format('n/d/Y'),
                'date paid' => $order->datePaid->format('n/d/Y'),
            ];

            fputcsv($fp, $row);
        }
        fclose($fp);

        return $fileName;
    }
}
