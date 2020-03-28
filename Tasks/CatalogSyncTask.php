<?php

namespace Plugin\TradeMaster\Tasks;

use Alksily\Entity\Collection;
use App\Domain\Tasks\Task;

class CatalogSyncTask extends Task
{
    public function execute(array $params = []): \App\Domain\Entities\Task
    {
        $default = [
            // nothing
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
    protected $categoryRepository;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    protected $productRepository;

    /**
     * @throws \RunTracy\Helpers\Profiler\Exception\ProfilerException
     */
    protected function action(array $args = [])
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->categoryRepository = $this->entityManager->getRepository(\App\Domain\Entities\Catalog\Category::class);
        $this->productRepository = $this->entityManager->getRepository(\App\Domain\Entities\Catalog\Product::class);

        $catalog = [
            'categories' => collect($this->categoryRepository->findBy([
                'export' => 'trademaster',
                'status' => \App\Domain\Types\Catalog\CategoryStatusType::STATUS_WORK,
            ])),
            'products' => collect($this->productRepository->findBy([
                'export' => 'trademaster',
                'status' => \App\Domain\Types\Catalog\ProductStatusType::STATUS_WORK
            ])),
        ];

        try {
            \RunTracy\Helpers\Profiler\Profiler::start('task:tm:category');
            $this->category($catalog['categories']);
            \RunTracy\Helpers\Profiler\Profiler::finish('task:tm:category');

            \RunTracy\Helpers\Profiler\Profiler::start('task:tm:product');
            $this->product($catalog['categories'], $catalog['products']);
            \RunTracy\Helpers\Profiler\Profiler::finish('task:tm:product');

            \RunTracy\Helpers\Profiler\Profiler::start('task:tm:remove');
            $this->remove($catalog['categories'], $catalog['products']);
            \RunTracy\Helpers\Profiler\Profiler::finish('task:tm:remove');

            $this->setStatusDone();
        } catch (\Exception $exception) {
            $this->setStatusFail();

            return;
        }
    }

    protected function category(Collection &$categories)
    {
        $this->logger->info('Task: TradeMaster get catalog item');

        // параметры отображения категории и товаров
        $template = [
            'category' => $this->getParameter('catalog_category_template', 'catalog.category.twig'),
            'product' => $this->getParameter('catalog_product_template', 'catalog.product.twig'),
        ];
        $pagination = $this->getParameter('catalog_category_pagination', 10);

        $list = $this->trademaster->api(['endpoint' => 'catalog/list']);

        foreach ($list as $item) {
            $data = [
                'external_id' => $item['idZvena'],
                'parent' => \Ramsey\Uuid\Uuid::NIL,
                'title' => $item['nameZvena'],
                'order' => $item['poryadok'],
                'description' => urldecode($item['opisanie']),
                'address' => $item['link'],
                'field1' => $item['ind1'],
                'field2' => $item['ind2'],
                'field3' => $item['ind3'],
                'template' => $template,
                'children' => true,
                'meta' => [
                    'title' => $item['nameZvena'],
                    'description' => strip_tags(urldecode($item['opisanie'])),
                ],
                'pagination' => $pagination,
                'export' => 'trademaster',
                'buf' => $item['idParent'],
            ];

            $result = \App\Domain\Filters\Catalog\Category::check($data);

            if ($result === true) {
                $model = $categories->firstWhere('external_id', $item['idZvena']);
                if (!$model) {
                    $categories[] = $model = new \App\Domain\Entities\Catalog\Category();
                }
                $model->replace($data);
                $this->entityManager->persist($model);

                if ($this->getParameter('file_is_enabled', 'no') === 'yes') {
                    $task = new \Plugin\TradeMaster\Tasks\DownloadImageTask($this->container);
                    $task->execute(['photo' => $item['foto'], 'type' => 'category', 'uuid' => $model->uuid->toString()]);
                }
            } else {
                $this->logger->warning('TradeMaster: invalid category data', $result);
            }
        }

        // обрабатываем связи
        foreach ($categories as $model) {
            /** @var \App\Domain\Entities\Catalog\Category $model */
            if (+$model->buf) {
                $model->set('parent', $categories->firstWhere('external_id', $model->buf)->get('uuid'));
            } else {
                $model->set('parent', \Ramsey\Uuid\Uuid::fromString(\Ramsey\Uuid\Uuid::NIL));
            }
        }

        // обрабатываем адреса
        if ($this->getParameter('common_auto_generate_address', 'no') === 'yes') {
            foreach ($categories as $model) {
                /**
                 * @var \App\Domain\Entities\Catalog\Category $category
                 * @var \App\Domain\Entities\Catalog\Category $model
                 */
                $category = $categories->firstWhere('uuid', $model->parent);

                if ($category && !str_starts_with($category->address, $model->address)) {
                    $model->address = $category->address . '/' . $model->address;
                }
            }
        }
    }

    protected function product(Collection &$categories, Collection &$products)
    {
        $this->logger->info('Task: TradeMaster get product item');

        $count = $this->trademaster->api(['endpoint' => 'item/count']);

        if ($count) {
            $count = (int)$count['count'];
            $i = 0;
            $step = 250;
            $go = true;

            // получаем данные
            while ($go) {
                $list = $this->trademaster->api([
                    'endpoint' => 'item/list',
                    'params' => [
                        'sklad' => $this->getParameter('TradeMasterPlugin_storage', 0),
                        'offset' => $i * $step,
                        'limit' => $step,
                    ],
                ]);

                // полученные данные проверяем и записываем в модели товара
                foreach ($list as $item) {
                    $data = [
                        'external_id' => $item['idTovar'],
                        'category' => \Ramsey\Uuid\Uuid::NIL,
                        'title' => $item['name'],
                        'order' => $item['poryadok'],
                        'description' => urldecode($item['opisanie']),
                        'extra' => urldecode($item['opisanieDop']),
                        'address' => $item['link'],
                        'field1' => $item['ind1'],
                        'field2' => $item['ind2'],
                        'field3' => $item['ind3'],
                        'field4' => $item['ind3'],
                        'field5' => $item['ind3'],
                        'vendorcode' => $item['artikul'],
                        'barcode' => $item['strihKod'],
                        'priceFirst' => $item['sebestomost'],
                        'price' => $item['price'],
                        'priceWholesale' => $item['opt_price'],
                        'unit' => $item['edIzmer'],
                        'volume' => $item['ves'],
                        'country' => $item['strana'],
                        'manufacturer' => $item['proizv'],
                        'tags' => $item['tags'],
                        'date' => new \DateTime($item['changeDate']),
                        'meta' => [
                            'title' => $item['name'],
                            'description' => strip_tags(urldecode($item['opisanie'])),
                        ],
                        'stock' => $item['kolvo'],
                        'export' => 'trademaster',
                        'buf' => 1,
                    ];

                    /**
                     * @var \App\Domain\Entities\Catalog\Category $category
                     * @var \App\Domain\Entities\Catalog\Product $model
                     */
                    $model = $products->firstWhere('external_id', $item['idTovar']);
                    if (!$model) {
                        $products[] = $model = new \App\Domain\Entities\Catalog\Product();
                    }

                    $result = \App\Domain\Filters\Catalog\Product::check($data);

                    if ($result === true) {
                        if (($category = $categories->firstWhere('external_id', $item['vStrukture'])) !== null) {
                            $data['category'] = $category->uuid;

                            if ($this->getParameter('common_auto_generate_address', 'no') === 'yes') {
                                $data['address'] = $category->address . '/' . $data['address'];
                            }
                        }

                        $model->replace($data);
                        $this->entityManager->persist($model);

                        if ($item['foto'] && $this->getParameter('file_is_enabled', 'no') === 'yes') {
                            $task = new \Plugin\TradeMaster\Tasks\DownloadImageTask($this->container);
                            $task->execute(['photo' => $item['foto'], 'type' => 'product', 'uuid' => $model->uuid->toString()]);
                        }
                    } else {
                        $this->logger->warning('TradeMaster: invalid product data', $result);
                    }
                }

                $go = $step * ++$i <= $count;
            }
        };
    }

    protected function remove(Collection &$categories, Collection &$products)
    {
        // удаление моделей категорий которые не получили обновление в процессе синхронизации
        foreach ($categories->where('buf', null) as $model) {
            /**
             * @var \App\Domain\Entities\Catalog\Category $model
             * @var \App\Domain\Entities\Catalog\Category $category
             * @var \App\Domain\Entities\Catalog\Product  $product
             */
            $childCategoriesUuid = \App\Domain\Entities\Catalog\Category::getChildren($categories, $model)->pluck('uuid')->all();

            // удаление вложенных категорий
            foreach ($categories->whereIn('uuid', $childCategoriesUuid) as $category) {
                $category->set('status', \App\Domain\Types\Catalog\CategoryStatusType::STATUS_DELETE);
                $this->entityManager->persist($category);
            }

            // удаление продуктов
            foreach ($products->whereIn('uuid', $childCategoriesUuid) as $product) {
                $product->set('status', \App\Domain\Types\Catalog\ProductStatusType::STATUS_DELETE);
                $this->entityManager->persist($product);
            }

            $model->set('status', \App\Domain\Types\Catalog\CategoryStatusType::STATUS_DELETE);
        }

        // удаление моделей продуктов которые не получили обновление в процессе синхронизации
        foreach ($products->where('buf', null) as $model) {
            /** @var \App\Domain\Entities\Catalog\Product $model */
            $model->set('status', \App\Domain\Types\Catalog\ProductStatusType::STATUS_DELETE);
            $this->entityManager->persist($model);
        }
    }
}