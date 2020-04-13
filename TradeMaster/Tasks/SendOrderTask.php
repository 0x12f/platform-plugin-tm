<?php

namespace Plugin\TradeMaster\Tasks;

use App\Domain\Tasks\Task;

class SendOrderTask extends Task
{
    public const TITLE = 'Отправка заказа в ТМ';

    public function execute(array $params = []): \App\Domain\Entities\Task
    {
        $default = [
            'item' => '',
        ];
        $params = array_merge($default, $params);

        return parent::execute($params);
    }

    /**
     * @var \Plugin\TradeMaster\TradeMasterPlugin
     */
    protected $trademaster;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    protected $productRepository;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    protected $orderRepository;

    protected function action(array $args = [])
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->productRepository = $this->entityManager->getRepository(\App\Domain\Entities\Catalog\Product::class);
        $this->orderRepository = $this->entityManager->getRepository(\App\Domain\Entities\Catalog\Order::class);

        /** @var \App\Domain\Entities\Catalog\Order $order */
        $order = $this->orderRepository->findOneBy(['uuid' => $args['uuid']]);
        if ($order) {
            if ($order->external_id) {
                return $this->setStatusCancel();
            }

            $products = [];

            /** @var \App\Domain\Entities\Catalog\Product $model */
            foreach ($this->productRepository->findBy(['uuid' => array_keys($order->list)]) as $model) {
                if ($model->external_id) {
                    $quantity = $order->list[$model->uuid->toString()];
                    $products[] = [
                        'id' => $model->external_id,
                        'name' => $model->title,
                        'quantity' => $quantity,
                        'price' => (float)$model->price * $quantity,
                    ];
                }
            }

            $result = $this->trademaster->api([
                'method' => 'POST',
                'endpoint' => 'order/cart/anonym',
                'params' => [
                    'sklad' => $this->getParameter('TradeMasterPlugin_storage'),
                    'urlico' => $this->getParameter('TradeMasterPlugin_legal'),
                    'ds' => $this->getParameter('TradeMasterPlugin_checkout'),
                    'kontragent' => $this->getParameter('TradeMasterPlugin_contractor'),
                    'shema' => $this->getParameter('TradeMasterPlugin_scheme'),
                    'valuta' => $this->getParameter('TradeMasterPlugin_currency'),
                    'userID' => $this->getParameter('TradeMasterPlugin_user'),
                    'nameKontakt' => $order->delivery['client'] ?? '',
                    'adresKontakt' => $order->delivery['address'] ?? '',
                    'telefonKontakt' => $order->phone,
                    'other1Kontakt' => $order->email,
                    'dateDost' => $order->shipping->format('Y-m-d H:i:s'),
                    'komment' => $order->comment,
                    'tovarJson' => json_encode($products, JSON_UNESCAPED_UNICODE),
                ],
            ]);

            if ($result && !empty($result['nomerZakaza'])) {
                $order->external_id = $result['nomerZakaza'];

                $products = collect($this->productRepository->findBy(['uuid' => array_keys($order->list)]));

                // письмо клиенту
                if (
                    $order->email &&
                    ($tpl = $this->getParameter('TradeMasterPlugin_mail_client_template', '')) !== ''
                ) {
                    // add task send client mail
                    $task = new \App\Domain\Tasks\SendMailTask($this->container);
                    $task->execute([
                        'to' => $order->email,
                        'body' => $this->render($tpl, ['order' => $order, 'products' => $products]),
                        'isHtml' => true,
                    ]);
                }

                return $this->setStatusDone();
            }
        }

        return $this->setStatusFail();
    }
}
