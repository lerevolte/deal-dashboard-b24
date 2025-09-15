<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Loader;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\StatusTable;
use Bitrix\Main\Error;
use CUserOptions;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\Model\RecentTable;

class CompanyDealDashboardComponent extends CBitrixComponent implements Controllerable
{
    private $assemblyStatuses = null;
    private $shippingWarehouses = null;
    private $filterWarehouses = null;
    private $excludedProductIds = [23089, 111, 113];

    public function configureActions()
    {
        return [
            'getStageData' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'getDealProducts' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'getDealsByProduct' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'moveDeal' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'saveHeights' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'findDeal' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'createDealChat' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'updateAssemblyStatus' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'updateShippingWarehouse' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'saveSortOptions' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'joinChat' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'getDealsForCsv' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'getProductsForCsv' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ],
            'getStageCounts' => [ 'prefilters' => [ new ActionFilter\Csrf() ] ]
        ];
    }

    private function getUsersInfo(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $users = [];
        // Убираем дубликаты ID для оптимизации запроса
        $uniqueUserIds = array_unique(array_filter($userIds));
        if (empty($uniqueUserIds)) {
            return [];
        }

        $result = \Bitrix\Main\UserTable::getList([
            'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN'],
            'filter' => ['@ID' => $uniqueUserIds]
        ]);

        while ($user = $result->fetch()) {
            $fullName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
            $users[$user['ID']] = !empty($fullName) ? $fullName : $user['LOGIN'];
        }

        return $users;
    }


    public function getStageCountsAction($warehouseFilterId = null, $productSearchQuery = null)
    {
        if (!Loader::includeModule('crm')) {
            throw new \Bitrix\Main\SystemException('Модуль CRM не установлен.');
        }

        $arFilter = ['CHECK_PERMISSIONS' => 'N'];

        if ($warehouseFilterId !== null && $warehouseFilterId !== '') {
            if ($warehouseFilterId === '0') {
                $arFilter['=UF_CRM_1753786869'] = false;
            } else {
                $arFilter['=UF_CRM_1753786869'] = $warehouseFilterId;
            }
        }

        if (!empty($productSearchQuery)) {
            $productDealIds = $this->getDealIdsByProductTitle($productSearchQuery);
            if (empty($productDealIds)) {
                return []; // No deals found for product, so all counts are 0
            }
            $arFilter['@ID'] = $productDealIds;
        }
        
        $dealsRaw = [];
        // Use CCrmDeal for filter by UF field
        $dbRes = \CCrmDeal::GetListEx([], $arFilter, false, false, ['ID', 'STAGE_ID']);
        while($deal = $dbRes->Fetch()) {
            $dealsRaw[] = $deal;
        }

        $dealCounts = array_count_values(array_column($dealsRaw, 'STAGE_ID'));

        $finalCounts = [];
        $pickupStages = ['13', '26'];
        $purchaseStages = ['21', '27'];

        $finalCounts['13_26'] = ($dealCounts['13'] ?? 0) + ($dealCounts['26'] ?? 0);
        $finalCounts['21_27'] = ($dealCounts['21'] ?? 0) + ($dealCounts['27'] ?? 0);

        foreach ($dealCounts as $stageId => $count) {
            if (!in_array($stageId, $pickupStages) && !in_array($stageId, $purchaseStages)) {
                $finalCounts[$stageId] = $count;
            }
        }

        return $finalCounts;
    }

    public function updateShippingWarehouseAction($dealId, $warehouseId)
    {
        if (!Loader::includeModule('crm') || !Loader::includeModule('bizproc')) {
            throw new \Bitrix\Main\SystemException('Необходимые модули (CRM, Бизнес-процессы) не установлены.');
        }

        $dealId = (int)$dealId;
        $warehouseId = $warehouseId ? (int)$warehouseId : false;

        $deal = new \CCrmDeal(false);
        $fields = ['UF_CRM_1755602273' => $warehouseId];
        if (!$deal->Update($dealId, $fields, false, false, ['DISABLE_USER_FIELD_CHECK' => true])) {
            throw new \Bitrix\Main\SystemException($deal->LAST_ERROR ?: 'Ошибка при обновлении поля склада.');
        }

        $workflowTemplateId = 408;
        $arErrorsTmp = [];
        $documentId = ['crm', 'CCrmDocumentDeal', 'DEAL_' . $dealId];

        CBPDocument::StartWorkflow(
            $workflowTemplateId,
            $documentId,
            [],
            $arErrorsTmp
        );

        if (!empty($arErrorsTmp)) {
            $errorMessages = [];
            foreach ($arErrorsTmp as $error) {
                $errorMessages[] = $error['message'];
            }
            throw new \Bitrix\Main\SystemException(implode(', ', $errorMessages));
        }

        return ['success' => true];
    }

    private function getDealIdsByProductTitle($searchQuery)
    {
        if (!Loader::includeModule('crm') || !Loader::includeModule('iblock')) {
            $this->addError(new Error('Необходимые модули не установлены.'));
            return null;
        }

        $searchQuery = trim($searchQuery);
        if (empty($searchQuery)) {
            return [];
        }

        $allProductIds = [];

        $productRowsByName = ProductRowTable::getList([
            'select' => ['PRODUCT_ID'],
            'filter' => [
                '%PRODUCT_NAME' => $searchQuery,
                '=OWNER_TYPE' => 'D',
                '!@PRODUCT_ID' => $this->excludedProductIds
            ]
        ])->fetchAll();
        if (!empty($productRowsByName)) {
            $allProductIds = array_merge($allProductIds, array_column($productRowsByName, 'PRODUCT_ID'));
        }

        $setIdsByName = [];
        $dbResSets = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => 23, '%NAME' => $searchQuery],
            false,
            false,
            ['ID']
        );
        while ($set = $dbResSets->Fetch()) {
            $setIdsByName[] = $set['ID'];
        }

        if (!empty($setIdsByName)) {
            $allProductIds = array_merge($allProductIds, $setIdsByName);

            $productIdsInSets = [];
            $dbResProductsInSet = \CIBlockElement::GetList(
                [],
                [
                    'IBLOCK_ID' => 23,
                    '=PROPERTY_170' => $setIdsByName
                ],
                false,
                false,
                ['ID']
            );
            while ($product = $dbResProductsInSet->Fetch()) {
                $productIdsInSets[] = $product['ID'];
            }
            
            if (!empty($productIdsInSets)) {
                $allProductIds = array_merge($allProductIds, $productIdsInSets);
            }
        }
        
        if (empty($allProductIds)) {
            return [];
        }

        $uniqueProductIds = array_unique($allProductIds);

        $productRows = ProductRowTable::getList([
            'select' => ['OWNER_ID'],
            'filter' => [
                '@PRODUCT_ID' => $uniqueProductIds,
                '=OWNER_TYPE' => 'D'
            ]
        ])->fetchAll();

        if (empty($productRows)) {
            return [];
        }

        return array_filter(array_unique(array_column($productRows, 'OWNER_ID')));
    }

    public function getDealsForCsvAction($stageId, $warehouseFilterId = null, $productSearchQuery = null)
    {
        if (!Loader::includeModule('crm')) {
            throw new \Bitrix\Main\SystemException('Модуль CRM не установлен.');
        }

        $arFilter = [];
        if (!empty($productSearchQuery)) {
            $productDealIds = $this->getDealIdsByProductTitle($productSearchQuery);
            $arFilter['@ID'] = !empty($productDealIds) ? $productDealIds : [0];
        }
        
        if ($warehouseFilterId !== null && $warehouseFilterId !== '') {
            if ($warehouseFilterId === '0') {
                $arFilter['=UF_CRM_1753786869'] = false;
            } else {
                $arFilter['=UF_CRM_1753786869'] = $warehouseFilterId;
            }
        }
        
        $stageFilter = $this->getStageFilter($stageId);
        $arOrder = ['MOVED_TIME' => 'ASC'];
        $arFilter['@STAGE_ID'] = $stageFilter;
        $arFilter['CHECK_PERMISSIONS'] = 'N';
        
        $arSelect = ['ID', 'TITLE', 'MOVED_TIME', 'DATE_CREATE', 'UF_CRM_1738582841', 'UF_CRM_1755005612', 'UF_CRM_1755602273', 'COMMENTS', 'UF_CRM_1753786869', 'ASSIGNED_BY_ID'];
        
        $deals = [];
        $dbRes = \CCrmDeal::GetListEx($arOrder, $arFilter, false, false, $arSelect);
        while ($deal = $dbRes->GetNext()) $deals[] = $deal;
        
        $dealIds = array_column($deals, 'ID');
        $assignedByIds = array_column($deals, 'ASSIGNED_BY_ID');
        $usersInfo = $this->getUsersInfo($assignedByIds);

        // Получаем информацию о времени на стадии
        if ($stageId === '20') {
            $assemblyTimes = $this->getDealsAssemblyTimeInMinutes($dealIds);
        } else {
            $assemblyTimes = $this->getDealsAssemblyTime($dealIds);
        }

        $closingDocs = $this->getClosingDocuments($dealIds);
        $totalWeights = $this->getDealsTotalWeight($dealIds);

        foreach ($deals as &$deal) {
            if (isset($assemblyTimes[$deal['ID']])) $deal['assemblyTime'] = $assemblyTimes[$deal['ID']];
            if (isset($totalWeights[$deal['ID']])) $deal['totalWeight'] = $totalWeights[$deal['ID']];
            
            if (isset($closingDocs[$deal['ID']])) {
                $titles = array_column($closingDocs[$deal['ID']], 'TITLE');
                $dates = array_filter(array_column($closingDocs[$deal['ID']], 'DATE'));

                $deal['closingDoc'] = $closingDocs[$deal['ID']];
                $deal['CLOSING_DOC_TITLE'] = implode(', ', $titles);

                if (!empty($dates)) {
                    $formattedDates = array_map(fn($date) => $date->format('d.m.Y'), $dates);
                    $deal['CLOSING_DOC_DATE'] = implode(', ', array_unique($formattedDates));
                } else {
                    $deal['CLOSING_DOC_DATE'] = null;
                }
            } else {
                $deal['CLOSING_DOC_TITLE'] = '';
                $deal['CLOSING_DOC_DATE'] = null;
            }
            $deal['ASSIGNED_BY_NAME'] = $usersInfo[$deal['ASSIGNED_BY_ID']] ?? '';
        }
        unset($deal);

        return $deals;
    }

    public function getProductsForCsvAction($stageId)
    {
        if (!Loader::includeModule('crm')) {
            throw new \Bitrix\Main\SystemException('Модуль CRM не установлен.');
        }

        $filter = $this->getStageFilter($stageId);
        $arFilter = ['@STAGE_ID' => $filter, 'CHECK_PERMISSIONS' => 'N'];
        
        $deals = [];
        $dbRes = \CCrmDeal::GetListEx([], $arFilter, false, false, ['ID']);
        while ($deal = $dbRes->GetNext()) {
            $deals[] = $deal;
        }
        
        if (empty($deals)) {
            return [];
        }

        $dealIds = array_column($deals, 'ID');
        
        $productRows = ProductRowTable::getList([
            'select' => ['PRODUCT_ID', 'PRODUCT_NAME', 'QUANTITY'], 
            'filter' => ['=OWNER_TYPE' => 'D', '@OWNER_ID' => $dealIds]
        ])->fetchAll();

        $productRows = array_filter($productRows, function($row) {
            return !in_array($row['PRODUCT_ID'], $this->excludedProductIds);
        });
        
        if (empty($productRows)) {
            return [];
        }

        $productIds = array_unique(array_column($productRows, 'PRODUCT_ID'));
        $productsSetInfo = $this->getProductsSetInfo($productIds);

        $productsAggregated = $this->aggregateProducts($productRows, $productsSetInfo);
        
        if (!empty($productsAggregated)) {
            usort($productsAggregated, fn($a, $b) => ($b['TOTAL_QUANTITY'] ?? 0) <=> ($a['TOTAL_QUANTITY'] ?? 0));
        }

        return $productsAggregated;
    }

    public function saveSortOptionsAction($sortOptions = [])
    {
        if (is_array($sortOptions)) {
            CUserOptions::SetOption('company.deal.dashboard', 'sort_options', $sortOptions);
            return ['success' => true];
        }
        return ['success' => false];
    }

    public function updateAssemblyStatusAction($dealId, $statusId)
    {
        if (!Loader::includeModule('crm')) throw new \Bitrix\Main\SystemException('Модуль CRM не установлен.');
        $dealId = (int)$dealId;
        $statusId = $statusId ? (int)$statusId : false;
        $deal = new \CCrmDeal(false);
        $fields = ['UF_CRM_1755005612' => $statusId];
        if ($deal->Update($dealId, $fields, true, true, ['DISABLE_USER_FIELD_CHECK' => true])) return ['success' => true];
        else throw new \Bitrix\Main\SystemException($deal->LAST_ERROR ?: 'Ошибка при обновлении статуса сборки.');
    }

    private function getClosingDocuments(array $dealIds): array
    {
        if (empty($dealIds) || !Loader::includeModule('crm')) return [];
        try {
            $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(1056);
            if (!$factory) return [];
            
            $items = $factory->getItems([
                'select' => ['ID', 'TITLE', 'PARENT_ID_2', 'UF_CRM_10_DATE'], 
                'filter' => ['@PARENT_ID_2' => $dealIds]
            ]);

            $documents = [];
            foreach ($items as $item) {
                $dealId = $item->get('PARENT_ID_2');
                if ($dealId) {
                    if (!isset($documents[$dealId])) {
                        $documents[$dealId] = [];
                    }
                    $documents[$dealId][] = [
                        'ID' => $item->getId(), 
                        'TITLE' => $item->getTitle(),
                        'DATE' => $item->get('UF_CRM_10_DATE') 
                    ];
                }
            }
            return $documents;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getProductsProperties(array $elementIds): array
    {
        if (empty($elementIds) || !Loader::includeModule('iblock')) {
            return [];
        }

        $properties = [];
        $select = [
            'ID',
            'IBLOCK_ID',
            'PROPERTY_135',
            'PROPERTY_145',
            'PROPERTY_148',
            'PROPERTY_173',
            'PROPERTY_172',
            'PROPERTY_134', // ADDED
        ];

        $dbRes = \CIBlockElement::GetList(
            [],
            ['ID' => array_unique($elementIds)],
            false,
            false,
            $select
        );

        while ($element = $dbRes->Fetch()) {
            $properties[$element['ID']] = [
                'PROPERTY_135_VALUE' => $element['PROPERTY_135_VALUE'],
                'PROPERTY_145_VALUE' => $element['PROPERTY_145_VALUE'],
                'PROPERTY_148_VALUE' => $element['PROPERTY_148_VALUE'],
                'PROPERTY_173_VALUE' => $element['PROPERTY_173_VALUE'],
                'PROPERTY_172_VALUE' => $element['PROPERTY_172_VALUE'],
                'PROPERTY_134_VALUE' => $element['PROPERTY_134_VALUE'], // ADDED
            ];
        }

        return $properties;
    }

    public function joinChatAction($chatId)
    {
        global $USER;
        if (!$USER->IsAdmin()) {
            throw new \Bitrix\Main\SystemException('Доступ запрещен.');
        }
        if (!Loader::includeModule('im')) {
            throw new \Bitrix\Main\SystemException('Модуль im не установлен.');
        }

        $chatId = (int)$chatId;
        if ($chatId <= 0) {
            throw new \Bitrix\Main\SystemException('Некорректный ID чата.');
        }

        $chat = new \CIMChat(0);
        $result = $chat->AddUser($chatId, $USER->GetID(), null, false, false);

        if (!$result) {
            global $APPLICATION;
            if ($e = $APPLICATION->GetException()) {
                throw new \Bitrix\Main\SystemException($e->GetString());
            }
            throw new \Bitrix\Main\SystemException('Не удалось добавить пользователя в чат.');
        }

        return ['success' => true];
    }

    public function createDealChatAction($dealId)
    {
        global $USER;
        if (!Loader::includeModule('crm') || !Loader::includeModule('im')) {
            throw new \Bitrix\Main\SystemException('Необходимые модули не установлены.');
        }

        $dealId = (int)$dealId;
        if ($dealId <= 0) {
            throw new \Bitrix\Main\SystemException('Некорректный ID сделки.');
        }

        $deal = \CCrmDeal::GetByID($dealId, false);
        if (!$deal) {
            throw new \Bitrix\Main\SystemException('Сделка не найдена.');
        }
        $dealTitle = $deal['TITLE'];
        $assignedById = $deal['ASSIGNED_BY_ID'];

        $userIds = [$USER->GetID()];
        if ($assignedById) {
            $userIds[] = $assignedById;
        }
        $uniqueUserIds = array_unique($userIds);

        $chat = new \CIMChat();
        $chatId = $chat->Add([
            'TITLE' => "Чат по сделке: " . $dealTitle,
            'TYPE' => IM_MESSAGE_CHAT,
            'ENTITY_TYPE' => 'CRM',
            'ENTITY_ID' => 'DEAL|' . $dealId,
            'USERS' => $uniqueUserIds
        ]);

        if ($chatId > 0) {
            return ['success' => true, 'chatId' => $chatId];
        } else {
            global $APPLICATION;
            $exception = $APPLICATION->GetException();
            $errorMessage = $exception ? $exception->GetString() : 'Не удалось создать чат.';
            throw new \Bitrix\Main\SystemException($errorMessage);
        }
    }

    public function findDealAction($dealId)
    {
        if (!Loader::includeModule('crm')) { $this->addError(new Error('Модуль CRM не установлен.')); return null; }
        if (!$dealId || !is_numeric($dealId)) { $this->addError(new Error('Некорректный ID сделки.')); return null; }
        $deal = DealTable::getRow(['filter' => ['=ID' => $dealId], 'select' => ['STAGE_ID']]);
        if ($deal) {
            $stageId = $deal['STAGE_ID'];
            if (in_array($stageId, ['13', '26'])) return ['stageId' => '13_26'];
            if (in_array($stageId, ['21', '27'])) return ['stageId' => '21_27'];
            return ['stageId' => $stageId];
        } else {
            $this->addError(new Error("Сделка с ID {$dealId} не найдена."));
            return null;
        }
    }

    private function getProductsSetInfo(array $productIds): array
    {
        if (empty($productIds) || !Loader::includeModule('iblock')) {
            return [];
        }

        $productsInfo = [];
        $setElementIds = [];

        $dbRes = \CIBlockElement::GetList(
            [],
            ['ID' => $productIds, 'IBLOCK_ID' => 23],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'PROPERTY_170']
        );
        while ($product = $dbRes->Fetch()) {
            if (!empty($product['PROPERTY_170_VALUE'])) {
                $productsInfo[$product['ID']] = ['SET_ID' => $product['PROPERTY_170_VALUE']];
                $setElementIds[] = $product['PROPERTY_170_VALUE'];
            }
        }

        if (empty($setElementIds)) {
            return $productsInfo;
        }
        
        $setElementIds = array_unique($setElementIds);

        $setNames = [];
        $dbRes = \CIBlockElement::GetList(
            [],
            ['ID' => $setElementIds],
            false,
            false,
            ['ID', 'NAME']
        );
        while ($set = $dbRes->Fetch()) {
            $setNames[$set['ID']] = $set['NAME'];
        }

        foreach ($productsInfo as $productId => &$info) {
            if (isset($setNames[$info['SET_ID']])) {
                $info['SET_NAME'] = $setNames[$info['SET_ID']];
            }
        }
        unset($info);

        return $productsInfo;
    }

    private function getDealsChatInfo(array $dealIds): array
    {
        global $USER, $DB;
        $userId = $USER->GetID();

        try {
            if (!$userId || empty($dealIds) || !Loader::includeModule('im')) {
                return [];
            }

            $chatInfo = [];
            
            $entityIds = [];
            foreach ($dealIds as $id) {
                $entityIds[] = "'DEAL|" . (int)$id . "'";
            }

            if (empty($entityIds)) {
                return [];
            }

            $strSql = "
                SELECT ID as CHAT_ID, ENTITY_ID as DEAL_ENTITY_ID
                FROM b_im_chat
                WHERE ENTITY_TYPE = 'CRM'
                  AND ENTITY_ID IN (" . implode(',', $entityIds) . ")
            ";
            $chatsResult = $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

            $chatIdToDealIdMap = [];
            $chatIds = [];
            while ($chat = $chatsResult->Fetch()) {
                $parts = explode('|', $chat['DEAL_ENTITY_ID']);
                if (count($parts) === 2 && $parts[0] === 'DEAL') {
                    $dealId = (int)$parts[1];
                    $chatId = (int)$chat['CHAT_ID'];
                    
                    if ($dealId > 0 && in_array($dealId, $dealIds)) {
                        $chatIdToDealIdMap[$chatId] = $dealId;
                        $chatIds[] = $chatId;
                    }
                }
            }

            if (empty($chatIds)) {
                return [];
            }

            $recentData = [];
            $recentResult = RecentTable::getList([
                'filter' => [
                    '=USER_ID' => $userId,
                    '=ITEM_TYPE' => 'C',
                    '@ITEM_ID' => $chatIds,
                ],
                'select' => ['ITEM_ID', 'UNREAD']
            ]);
            while ($recent = $recentResult->fetch()) {
                $recentData[$recent['ITEM_ID']] = ($recent['UNREAD'] === 'Y');
            }

            $relationResult = RelationTable::getList([
                'filter' => ['=USER_ID' => $userId, '@CHAT_ID' => $chatIds],
                'select' => ['CHAT_ID']
            ]);
            while($relation = $relationResult->fetch()) {
                $userRelations[$relation['CHAT_ID']] = true;
            }

            foreach ($chatIdToDealIdMap as $chatId => $dealId) {
                $isUnread = !array_key_exists($chatId, $recentData) || $recentData[$chatId] === true;
                $chatInfo[$dealId] = [
                    'chatId' => $chatId,
                    'isUnread' => $isUnread,
                    'isMember' => isset($userRelations[$chatId])
                ];
            }
            return $chatInfo;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getDealsTotalWeight(array $dealIds): array
    {
        if (empty($dealIds) || !Loader::includeModule('crm') || !Loader::includeModule('iblock')) {
            return [];
        }
    
        $productRows = ProductRowTable::getList([
            'select' => ['OWNER_ID', 'PRODUCT_ID', 'QUANTITY'],
            'filter' => ['=OWNER_TYPE' => 'D', '@OWNER_ID' => $dealIds]
        ])->fetchAll();
    
        if (empty($productRows)) {
            return [];
        }
    
        $productIds = array_unique(array_column($productRows, 'PRODUCT_ID'));
        
        $productWeights = [];
        $dbRes = \CIBlockElement::GetList(
            [],
            ['ID' => $productIds],
            false,
            false,
            ['ID', 'PROPERTY_134']
        );
        while ($element = $dbRes->Fetch()) {
            $productWeights[$element['ID']] = (float)$element['PROPERTY_134_VALUE'];
        }
    
        $dealsWeight = [];
        foreach ($productRows as $row) {
            $dealId = $row['OWNER_ID'];
            $productId = $row['PRODUCT_ID'];
            $quantity = (float)$row['QUANTITY'];
            $weight = ($productWeights[$productId] ?? 0) * $quantity;
            
            if (!isset($dealsWeight[$dealId])) {
                $dealsWeight[$dealId] = 0;
            }
            $dealsWeight[$dealId] += $weight;
        }
    
        return $dealsWeight;
    }
    
    private function getDealsAssemblyTime(array $dealIds): array
    {
        if (empty($dealIds)) {
            return [];
        }

        $assemblyTimes = [];
        
        $dealsResult = DealTable::getList([
            'select' => ['ID', 'MOVED_TIME'],
            'filter' => ['@ID' => $dealIds]
        ]);

        $nowTimestamp = (new \Bitrix\Main\Type\DateTime())->getTimestamp();

        while ($deal = $dealsResult->fetch()) {
            $dealId = (int)$deal['ID'];
            
            if ($deal['MOVED_TIME'] instanceof \Bitrix\Main\Type\DateTime) {
                $entryTimestamp = $deal['MOVED_TIME']->getTimestamp();
                $diff = $nowTimestamp - $entryTimestamp;
                $days = floor($diff / (60 * 60 * 24));
                
                if ($days < 0) {
                    $days = 0;
                }
                
                $assemblyTimes[$dealId] = $days;
            }
        }

        return $assemblyTimes;
    }

    private function getDealsAssemblyTimeInMinutes(array $dealIds): array
    {
        if (empty($dealIds)) {
            return [];
        }

        $assemblyTimes = [];
        
        $dealsResult = DealTable::getList([
            'select' => ['ID', 'MOVED_TIME'],
            'filter' => ['@ID' => $dealIds]
        ]);

        $nowTimestamp = (new \Bitrix\Main\Type\DateTime())->getTimestamp();

        while ($deal = $dealsResult->fetch()) {
            $dealId = (int)$deal['ID'];
            
            if ($deal['MOVED_TIME'] instanceof \Bitrix\Main\Type\DateTime) {
                $entryTimestamp = $deal['MOVED_TIME']->getTimestamp();
                $diff = $nowTimestamp - $entryTimestamp;
                $minutes = floor($diff / 60);
                
                if ($minutes < 0) {
                    $minutes = 0;
                }
                
                $assemblyTimes[$dealId] = $minutes;
            }
        }

        return $assemblyTimes;
    }

    public function saveHeightsAction($heights = [])
    {
        if (!empty($heights) && is_array($heights)) { CUserOptions::SetOption('company.deal.dashboard', 'window_heights', $heights); return ['success' => true]; }
        return ['success' => false];
    }

    public function moveDealAction($dealId, $targetStageId)
    {
        global $USER;
        if (!Loader::includeModule('crm')) throw new \Bitrix\Main\SystemException('Модуль CRM не установлен.');
        if (!$dealId || !$targetStageId) throw new \Bitrix\Main\SystemException('Не указан ID сделки или целевая стадия.');
        $CCrmDeal = new \CCrmDeal(false);
        $arFields = ['STAGE_ID' => $targetStageId];
        $result = $CCrmDeal->Update($dealId, $arFields, true, true, ['CURRENT_USER' => $USER->GetID()]);
        if ($result) return ['success' => true];
        else throw new \Bitrix\Main\SystemException($CCrmDeal->LAST_ERROR ?: 'Ошибка при обновлении сделки.');
    }

    private function getStageFilter($stageId)
    {
        if ($stageId === '13_26') return ['13', '26'];
        if ($stageId === '21_27') return ['21', '27'];
        return (array)$stageId;
    }

    private function applySorting(&$items, $sortKey, $sortOrder)
    {
        if (!$sortKey || empty($items)) return;

        $dateKeys = ['MOVED_TIME', 'DATE_CREATE', 'UF_CRM_1738582841', 'CLOSING_DOC_DATE'];
        $numericKeys = [
            'assemblyTime', 'QUANTITY', 'TOTAL_QUANTITY', 'ID', 'totalWeight',
            'PROPERTY_135_VALUE', 'PROPERTY_145_VALUE', 'ASSEMBLED_QTY',
            'DELIVERY_QTY', 'PICKUP_QTY', 'READY_FOR_SHIPMENT_QTY'
        ];

        usort($items, function($a, $b) use ($sortKey, $sortOrder, $dateKeys, $numericKeys) {
            $valA = $a[$sortKey] ?? null;
            $valB = $b[$sortKey] ?? null;

            if (in_array($sortKey, $dateKeys)) {
                $tsA = 0;
                if ($valA) $tsA = ($valA instanceof \Bitrix\Main\Type\DateTime) ? $valA->getTimestamp() : MakeTimeStamp($valA);
                $tsB = 0;
                if ($valB) $tsB = ($valB instanceof \Bitrix\Main\Type\DateTime) ? $valB->getTimestamp() : MakeTimeStamp($valB);
                $valA = $tsA;
                $valB = $tsB;
            } elseif (in_array($sortKey, $numericKeys)) {
                $valA = (float)preg_replace('/[^\d\.]/', '', (string)$valA);
                $valB = (float)preg_replace('/[^\d\.]/', '', (string)$valB);
            } elseif (is_string($valA)) {
                $valA = mb_strtolower($valA);
                $valB = mb_strtolower($valB);
            }

            if ($valA == $valB) return 0;
            $result = ($valA < $valB) ? -1 : 1;
            return ($sortOrder === 'DESC') ? -$result : $result;
        });
    }

    private function aggregateProducts(array $productRows, array $productsSetInfo): array
    {
        $productsAggregated = [];

        foreach ($productRows as $row) {
            $productId = $row['PRODUCT_ID'];
            $quantity = (float)($row['QUANTITY'] ?? $row['TOTAL_QUANTITY']);
            $setInfo = $productsSetInfo[$productId] ?? null;
            $setId = $setInfo['SET_ID'] ?? null;

            if ($setId) {
                $aggKey = 'set_' . $setId;
                if (!isset($productsAggregated[$aggKey])) {
                    $productsAggregated[$aggKey] = [
                        'PRODUCT_ID' => $setId,
                        'PRODUCT_NAME' => $setInfo['SET_NAME'] ?? 'Набор #' . $setId,
                        'QUANTITY' => 0,
                        'IS_SET' => true,
                    ];
                }
                $productsAggregated[$aggKey]['QUANTITY'] += $quantity;
            } else {
                $aggKey = 'prod_' . $productId;
                if (!isset($productsAggregated[$aggKey])) {
                    $productsAggregated[$aggKey] = [
                        'PRODUCT_ID' => $productId,
                        'PRODUCT_NAME' => $row['PRODUCT_NAME'],
                        'QUANTITY' => 0,
                        'IS_SET' => false,
                    ];
                }
                $productsAggregated[$aggKey]['QUANTITY'] += $quantity;
            }
        }

        $keysToProcess = array_keys($productsAggregated);
        foreach ($keysToProcess as $key) {
            if (!isset($productsAggregated[$key])) {
                continue;
            }
            
            if (strpos($key, 'prod_') === 0) {
                $item = $productsAggregated[$key];
                $productId = $item['PRODUCT_ID'];
                $potentialSetKey = 'set_' . $productId;

                if (isset($productsAggregated[$potentialSetKey])) {
                    $productsAggregated[$potentialSetKey]['QUANTITY'] += $item['QUANTITY'];
                    unset($productsAggregated[$key]);
                }
            }
        }

        $allSetIds = array_unique(array_filter(array_column($productsSetInfo, 'SET_ID')));
        foreach ($productsAggregated as &$item) {
            if (!$item['IS_SET'] && in_array($item['PRODUCT_ID'], $allSetIds)) {
                $item['IS_SET'] = true;
            }
            if (isset($item['QUANTITY'])) {
                $item['TOTAL_QUANTITY'] = $item['QUANTITY'];
                unset($item['QUANTITY']);
            }
        }
        unset($item);

        return array_values($productsAggregated);
    }
    
    private function getProductQuantitiesByWarehouse(array $productIds, $warehouseId = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        $quantities = [];
        foreach ($productIds as $id) {
            $quantities[$id] = ['assembly' => 0, 'delivery' => 0, 'pickup' => 0, 'assembled' => 0];
        }

        // Получаем количества для каждого типа стадий
        $deliveryQuantities = $this->getProductQuantitiesOnStages($productIds, ['17'], $warehouseId);
        $pickupQuantities = $this->getProductQuantitiesOnStages($productIds, ['13', '26'], $warehouseId);
        $assemblyQuantities = $this->getProductQuantitiesOnStages($productIds, ['20', '31', '28', '21', '27'], $warehouseId);
        $assembledQuantities = $this->getProductQuantitiesOnStages($productIds, ['25'], $warehouseId);

        // Объединяем результаты
        foreach ($productIds as $id) {
            $quantities[$id]['delivery'] = $deliveryQuantities[$id] ?? 0;
            $quantities[$id]['pickup'] = $pickupQuantities[$id] ?? 0;
            $quantities[$id]['assembly'] = $assemblyQuantities[$id] ?? 0;
            $quantities[$id]['assembled'] = $assembledQuantities[$id] ?? 0;
        }

        return $quantities;
    }

    private function getProductQuantitiesOnStages(array $productIds, array $stages, $warehouseId = null): array
    {
        if (empty($productIds) || empty($stages)) {
            return [];
        }

        $quantities = [];
        foreach ($productIds as $id) {
            $quantities[$id] = 0;
        }

        $filter = [
            '@STAGE_ID' => $stages,
            'CHECK_PERMISSIONS' => 'N'
        ];

        if ($warehouseId !== null) {
            if ($warehouseId === '0') {
                $filter['=UF_CRM_1753786869'] = false;
            } else if ($warehouseId !== '') {
                $filter['=UF_CRM_1753786869'] = $warehouseId;
            }
        }

        $dealIds = [];
        $dbRes = \CCrmDeal::GetListEx([], $filter, false, false, ['ID']);
        while ($deal = $dbRes->Fetch()) {
            $dealIds[] = $deal['ID'];
        }

        if (empty($dealIds)) {
            return $quantities;
        }

        // Получаем все товарные позиции из сделок
        $productRows = ProductRowTable::getList([
            'select' => ['PRODUCT_ID', 'QUANTITY'],
            'filter' => [
                '=OWNER_TYPE' => 'D',
                '@OWNER_ID' => $dealIds
            ]
        ])->fetchAll();

        // Фильтруем исключенные товары
        $productRows = array_filter($productRows, function($row) {
            return !in_array($row['PRODUCT_ID'], $this->excludedProductIds);
        });

        if (empty($productRows)) {
            return $quantities;
        }

        // Получаем информацию о наборах
        $allProductIdsFromDeals = array_unique(array_column($productRows, 'PRODUCT_ID'));
        $setInfoForAllProducts = $this->getProductsSetInfo($allProductIdsFromDeals);

        // Агрегируем продукты с учетом наборов
        $aggregatedProducts = $this->aggregateProducts($productRows, $setInfoForAllProducts);

        // Применяем количества
        foreach ($aggregatedProducts as $aggregatedProduct) {
            $productId = $aggregatedProduct['PRODUCT_ID'];
            $quantity = $aggregatedProduct['TOTAL_QUANTITY'] ?? 0;

            if (in_array($productId, $productIds)) {
                $quantities[$productId] += $quantity;
            }
        }

        return $quantities;
    }

    private function getProductReadyForShipmentQuantities(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $quantities = [];
        foreach($productIds as $id) {
            $quantities[$id] = [
                'total' => 0,
                'by_warehouse' => []
            ];
        }
        
        $shipmentStages = ['13', '17', '25'];
        $filter = ['@STAGE_ID' => $shipmentStages, 'CHECK_PERMISSIONS' => 'N'];
        
        $deals = [];
        $dbRes = \CCrmDeal::GetListEx([], $filter, false, false, ['ID', 'UF_CRM_1753786869']);
        while ($deal = $dbRes->Fetch()) {
            $deals[$deal['ID']] = $deal['UF_CRM_1753786869'] ?: 'none';
        }
        
        if (empty($deals)) {
            return $quantities;
        }
        
        $dealIds = array_keys($deals);
        $productRows = ProductRowTable::getList([
            'select' => ['OWNER_ID', 'PRODUCT_ID', 'QUANTITY'],
            'filter' => [
                '=OWNER_TYPE' => 'D',
                '@OWNER_ID' => $dealIds
            ]
        ])->fetchAll();

        // Фильтруем исключенные товары
        $productRows = array_filter($productRows, function($row) {
            return !in_array($row['PRODUCT_ID'], $this->excludedProductIds);
        });

        if (empty($productRows)) {
            return $quantities;
        }

        // Группируем по сделкам и агрегируем по складам
        $dealProducts = [];
        foreach ($productRows as $row) {
            $dealId = $row['OWNER_ID'];
            if (!isset($dealProducts[$dealId])) {
                $dealProducts[$dealId] = [];
            }
            $dealProducts[$dealId][] = $row;
        }

        // Обрабатываем каждую сделку отдельно
        foreach ($dealProducts as $dealId => $dealProductRows) {
            $warehouseId = $deals[$dealId];
            
            // Получаем информацию о наборах для товаров этой сделки
            $dealProductIds = array_unique(array_column($dealProductRows, 'PRODUCT_ID'));
            $setInfoForDeal = $this->getProductsSetInfo($dealProductIds);
            
            // Агрегируем продукты для этой сделки
            $aggregatedForDeal = $this->aggregateProducts($dealProductRows, $setInfoForDeal);
            
            // Применяем количества к нужным продуктам
            foreach ($aggregatedForDeal as $aggregatedProduct) {
                $productId = $aggregatedProduct['PRODUCT_ID'];
                $quantity = $aggregatedProduct['TOTAL_QUANTITY'] ?? 0;

                if (in_array($productId, $productIds)) {
                    $quantities[$productId]['total'] += $quantity;
                    if (!isset($quantities[$productId]['by_warehouse'][$warehouseId])) {
                        $quantities[$productId]['by_warehouse'][$warehouseId] = 0;
                    }
                    $quantities[$productId]['by_warehouse'][$warehouseId] += $quantity;
                }
            }
        }
        
        return $quantities;
    }

    public function getStageDataAction($stageId, $tableKey, $sortBy, $sortOrder, $productSearchQuery = null, $warehouseFilterId = null, $filterByProductId = null, $filterBySetId = null)
    {
        Loader::includeModule('crm');
        
        $arFilter = [];
        if (!empty($productSearchQuery))
        {
            $productDealIds = $this->getDealIdsByProductTitle($productSearchQuery);
            $arFilter['@ID'] = !empty($productDealIds) ? $productDealIds : [0];
        }
        
        if ($warehouseFilterId !== null && $warehouseFilterId !== '') {
            if ($warehouseFilterId === '0') { // '0' means not selected
                $arFilter['=UF_CRM_1753786869'] = false;
            } else {
                $arFilter['=UF_CRM_1753786869'] = $warehouseFilterId;
            }
        }

        // Фильтрация сделок по выбранному товару/набору
        if ($filterByProductId || $filterBySetId) {
            $dealIdsWithItems = [];
            
            if ($filterByProductId) {
                $rows = ProductRowTable::getList(['select' => ['OWNER_ID'], 'filter' => ['=OWNER_TYPE' => 'D', '=PRODUCT_ID' => $filterByProductId]])->fetchAll();
                $dealIdsWithItems = array_column($rows, 'OWNER_ID');
            } elseif ($filterBySetId) {
                $componentIds = [];
                $dbRes = \CIBlockElement::GetList([], ['IBLOCK_ID' => 23, '=PROPERTY_170' => $filterBySetId], false, false, ['ID']);
                while ($product = $dbRes->Fetch()) {
                    $componentIds[] = (int)$product['ID'];
                }
                
                $productIdsForFilter = $componentIds;
                $productIdsForFilter[] = (int)$filterBySetId;
                $productIdsForFilter = array_unique($productIdsForFilter);

                if (!empty($productIdsForFilter)) {
                    $rows = ProductRowTable::getList(['select' => ['OWNER_ID'], 'filter' => ['=OWNER_TYPE' => 'D', '@PRODUCT_ID' => $productIdsForFilter]])->fetchAll();
                    $dealIdsWithItems = array_unique(array_column($rows, 'OWNER_ID'));
                }
            }

            if (isset($arFilter['@ID'])) {
                $arFilter['@ID'] = array_intersect($arFilter['@ID'], $dealIdsWithItems);
                if (empty($arFilter['@ID'])) {
                    $arFilter['@ID'] = [0];
                }
            } else {
                $arFilter['@ID'] = !empty($dealIdsWithItems) ? $dealIdsWithItems : [0];
            }
        }
        
        $stageFilter = $this->getStageFilter($stageId);
        $arOrder = ['MOVED_TIME' => 'ASC'];
        $arFilter['@STAGE_ID'] = $stageFilter;
        $arFilter['CHECK_PERMISSIONS'] = 'N';
        
        $arSelect = ['ID', 'TITLE', 'MOVED_TIME', 'DATE_CREATE', 'UF_CRM_1738582841', 'UF_CRM_1755005612', 'UF_CRM_1755602273', 'COMMENTS', 'UF_CRM_1753786869', 'ASSIGNED_BY_ID'];
        
        $deals = [];
        $dbRes = \CCrmDeal::GetListEx($arOrder, $arFilter, false, false, $arSelect);
        while ($deal = $dbRes->GetNext()) $deals[] = $deal;
        
        $dealIds = array_column($deals, 'ID');
        $chatInfo = $this->getDealsChatInfo($dealIds);
        
        if ($stageId === '20') {
            $assemblyTimes = $this->getDealsAssemblyTimeInMinutes($dealIds);
        } else {
            $assemblyTimes = $this->getDealsAssemblyTime($dealIds);
        }
        $closingDocs = $this->getClosingDocuments($dealIds);
        $totalWeights = $this->getDealsTotalWeight($dealIds);

        $assignedByIds = array_column($deals, 'ASSIGNED_BY_ID');
        $usersInfo = $this->getUsersInfo($assignedByIds);

        foreach ($deals as &$deal) {
            if (isset($chatInfo[$deal['ID']])) $deal['chatInfo'] = $chatInfo[$deal['ID']];
            if (isset($assemblyTimes[$deal['ID']])) $deal['assemblyTime'] = $assemblyTimes[$deal['ID']];
            if (isset($totalWeights[$deal['ID']])) $deal['totalWeight'] = $totalWeights[$deal['ID']];
            
            if (isset($closingDocs[$deal['ID']])) {
                $deal['closingDoc'] = $closingDocs[$deal['ID']];
                $deal['CLOSING_DOC_TITLE'] = implode(', ', array_column($closingDocs[$deal['ID']], 'TITLE'));
                $dates = array_filter(array_column($closingDocs[$deal['ID']], 'DATE'));
                 if (!empty($dates)) {
                    $formattedDates = array_map(fn($date) => $date->format('d.m.Y'), $dates);
                    $deal['CLOSING_DOC_DATE'] = implode(', ', array_unique($formattedDates));
                } else {
                    $deal['CLOSING_DOC_DATE'] = null;
                }
            } else {
                $deal['CLOSING_DOC_TITLE'] = '';
                $deal['CLOSING_DOC_DATE'] = null;
            }

            // ОБНОВЛЕННАЯ ЛОГИКА ПРОСРОЧКИ ДЛЯ РАЗНЫХ СТАДИЙ
            $assemblyTime = $deal['assemblyTime'] ?? 0;
            $isOverdue = false;

            switch ($stageId) {
                case '13_26':
                case '17':
                    $isOverdue = $assemblyTime >= 20;
                    break;
                case '25':
                case 'FINAL_INVOICE':
                case 'NEW':
                    $isOverdue = $assemblyTime >= 1;
                    break;
                case '21_27':
                    $isOverdue = $assemblyTime >= 3;
                    break;
                case '31':
                    $isOverdue = $assemblyTime >= 2;
                    break;
                case '10':
                    $isOverdue = $assemblyTime >= 30;
                    break;
                case '20':
                    $isOverdue = $assemblyTime > 60; // для минут
                    break;
            }

            if ($isOverdue) {
                $deal['isOverdue'] = true;
            }

            $deal['ASSIGNED_BY_NAME'] = $usersInfo[$deal['ASSIGNED_BY_ID']] ?? '';
        }
        unset($deal);

        if ($tableKey === 'deals') {
            $this->applySorting($deals, $sortBy, $sortOrder);
        }
        
        $this->getAssemblyStatusFieldInfo();
        $this->getShippingWarehouseFieldInfo();
        $this->getFilterWarehouseFieldInfo();
        $dealsHtml = $this->renderItems($deals, 'deal', $stageId);

        $productsHtml = '';
        if (!empty($dealIds)) {
            $productRows = ProductRowTable::getList([
                'select' => ['PRODUCT_ID', 'PRODUCT_NAME', 'QUANTITY'],
                'filter' => ['=OWNER_TYPE' => 'D', '@OWNER_ID' => $dealIds]
            ])->fetchAll();

            $productRows = array_filter($productRows, function($row) {
                return !in_array($row['PRODUCT_ID'], $this->excludedProductIds);
            });

            $productIdsInStage = array_unique(array_column($productRows, 'PRODUCT_ID'));
            $setInfoForStageProducts = $this->getProductsSetInfo($productIdsInStage);
            $productsAggregated = $this->aggregateProducts($productRows, $setInfoForStageProducts);

            $allRelevantSetIds = [];
            $parentSetIds = array_unique(array_filter(array_column($setInfoForStageProducts, 'SET_ID')));
            if (!empty($parentSetIds)) {
                $allRelevantSetIds = array_merge($allRelevantSetIds, $parentSetIds);
            }
            foreach ($productsAggregated as $p) {
                if ($p['IS_SET']) {
                    $allRelevantSetIds[] = $p['PRODUCT_ID'];
                }
            }
            $allRelevantSetIds = array_unique($allRelevantSetIds);

            $allIdsForQuantities = $productIdsInStage;
            $allIdsForQuantities = array_merge($allIdsForQuantities, $allRelevantSetIds);

            if (!empty($allRelevantSetIds)) {
                $dbRes = \CIBlockElement::GetList([], ['IBLOCK_ID' => 23, '=PROPERTY_170' => $allRelevantSetIds], false, false, ['ID']);
                while ($product = $dbRes->Fetch()) {
                    $allIdsForQuantities[] = (int)$product['ID'];
                }
            }
            $allIdsForQuantities = array_unique($allIdsForQuantities);

            $productsSetInfo = $this->getProductsSetInfo($allIdsForQuantities);
            $readyForShipmentQuantities = $this->getProductReadyForShipmentQuantities($allIdsForQuantities);
            $pipelineQuantitiesIndividual = $this->getProductQuantitiesByWarehouse($allIdsForQuantities, null);
            // NEW: Get quantities for the "Сборка" column
            $assemblyPipelineQuantities = $this->getProductQuantitiesOnStages($allIdsForQuantities, ['31', '28', '21', '27']);


            if (!empty($productSearchQuery)) {
                $searchQuery = trim($productSearchQuery);
                $finalAggregatedProducts = [];
                foreach ($productsAggregated as $product) {
                    if (mb_stripos($product['PRODUCT_NAME'], $searchQuery) !== false) {
                        $finalAggregatedProducts[] = $product;
                    }
                }
                $productsAggregated = $finalAggregatedProducts;
            }
            
            $finalProductIds = array_column($productsAggregated, 'PRODUCT_ID');
            $productProperties = $this->getProductsProperties($finalProductIds);
            
            $this->getFilterWarehouseFieldInfo(); // Ensure warehouse names are loaded
            $yuzhnyPortId = null;
            $ramenskiyId = null;
            if ($this->filterWarehouses) {
                foreach ($this->filterWarehouses as $warehouse) {
                    if (mb_strpos($warehouse['VALUE'], 'Южнопортовый') !== false) {
                        $yuzhnyPortId = $warehouse['ID'];
                    }
                    if (mb_strpos($warehouse['VALUE'], 'Раменский') !== false) {
                        $ramenskiyId = $warehouse['ID'];
                    }
                }
            }

            foreach ($productsAggregated as &$product) {
                $elementId = $product['PRODUCT_ID'];
                if (isset($productProperties[$elementId])) {
                    $product = array_merge($product, $productProperties[$elementId]);
                }
                
                $readyQty = 0;
                $assemblyQty = 0;
                $deliveryQty = 0;
                $pickupQty = 0;
                $assembledQty = 0;
                $sborkaQty = 0; // For new column
                $readyQtyByWarehouse = [];

                if ($product['IS_SET']) {
                    foreach ($productsSetInfo as $individualId => $setInfo) {
                        if (isset($setInfo['SET_ID']) && $setInfo['SET_ID'] == $elementId) {
                            $assemblyQty += $pipelineQuantitiesIndividual[$individualId]['assembly'] ?? 0;
                            $deliveryQty += $pipelineQuantitiesIndividual[$individualId]['delivery'] ?? 0;
                            $pickupQty += $pipelineQuantitiesIndividual[$individualId]['pickup'] ?? 0;
                            $assembledQty += $pipelineQuantitiesIndividual[$individualId]['assembled'] ?? 0;
                            $sborkaQty += $assemblyPipelineQuantities[$individualId] ?? 0;

                            $componentReadyInfo = $readyForShipmentQuantities[$individualId] ?? ['total' => 0, 'by_warehouse' => []];
                            $readyQty += $componentReadyInfo['total'];
                            foreach($componentReadyInfo['by_warehouse'] as $whId => $whQty) {
                                if(!isset($readyQtyByWarehouse[$whId])) $readyQtyByWarehouse[$whId] = 0;
                                $readyQtyByWarehouse[$whId] += $whQty;
                            }
                        }
                    }
                    if (isset($pipelineQuantitiesIndividual[$elementId])) {
                        $assemblyQty += $pipelineQuantitiesIndividual[$elementId]['assembly'] ?? 0;
                        $deliveryQty += $pipelineQuantitiesIndividual[$elementId]['delivery'] ?? 0;
                        $pickupQty += $pipelineQuantitiesIndividual[$elementId]['pickup'] ?? 0;
                        $assembledQty += $pipelineQuantitiesIndividual[$elementId]['assembled'] ?? 0;
                    }
                    if (isset($assemblyPipelineQuantities[$elementId])) {
                         $sborkaQty += $assemblyPipelineQuantities[$elementId];
                    }

                    $setReadyInfo = $readyForShipmentQuantities[$elementId] ?? ['total' => 0, 'by_warehouse' => []];
                    $readyQty += $setReadyInfo['total'];
                    foreach($setReadyInfo['by_warehouse'] as $whId => $whQty) {
                        if(!isset($readyQtyByWarehouse[$whId])) $readyQtyByWarehouse[$whId] = 0;
                        $readyQtyByWarehouse[$whId] += $whQty;
                    }
                } else {
                    if (isset($pipelineQuantitiesIndividual[$elementId])) {
                        $assemblyQty = $pipelineQuantitiesIndividual[$elementId]['assembly'] ?? 0;
                        $deliveryQty = $pipelineQuantitiesIndividual[$elementId]['delivery'] ?? 0;
                        $pickupQty = $pipelineQuantitiesIndividual[$elementId]['pickup'] ?? 0;
                        $assembledQty = $pipelineQuantitiesIndividual[$elementId]['assembled'] ?? 0;
                    }
                    $sborkaQty = $assemblyPipelineQuantities[$elementId] ?? 0;

                    $productReadyInfo = $readyForShipmentQuantities[$elementId] ?? ['total' => 0, 'by_warehouse' => []];
                    $readyQty = $productReadyInfo['total'];
                    $readyQtyByWarehouse = $productReadyInfo['by_warehouse'];
                }

                $product['READY_FOR_SHIPMENT_QTY'] = $readyQty;
                $product['ASSEMBLY_QTY'] = $assemblyQty;
                $product['DELIVERY_QTY'] = $deliveryQty;
                $product['PICKUP_QTY'] = $pickupQty;
                $product['ASSEMBLED_QTY'] = $assembledQty;
                $product['SBORKA_QTY'] = $sborkaQty; // For new column
                
                $yuzhnyPortStock = (float)($product['PROPERTY_135_VALUE'] ?? 0);
                $ramenskiyStock = (float)($product['PROPERTY_145_VALUE'] ?? 0);
                
                $readyYuzhny = $yuzhnyPortId ? ($readyQtyByWarehouse[$yuzhnyPortId] ?? 0) : 0;
                $readyRamenskiy = $ramenskiyId ? ($readyQtyByWarehouse[$ramenskiyId] ?? 0) : 0;
                
                $product['READY_QTY_YUZHNY'] = $readyYuzhny;
                $product['READY_QTY_RAMENSKIY'] = $readyRamenskiy;

                $product['FREE_STOCK_YUZHNY'] = $yuzhnyPortStock - $readyYuzhny;
                $product['FREE_STOCK_RAMENSKIY'] = $ramenskiyStock - $readyRamenskiy;
            }
            unset($product);
            
            if ($tableKey === 'products') {
                $this->applySorting($productsAggregated, $sortBy, $sortOrder);
            } else {
                usort($productsAggregated, fn($a, $b) => ($b['TOTAL_QUANTITY'] ?? 0) <=> ($a['TOTAL_QUANTITY'] ?? 0));
            }

            $productsHtml = $this->renderItems($productsAggregated, 'product_with_total_quantity', $stageId);
        } else {
            $productsHtml = $this->renderItems([], 'product_with_total_quantity', $stageId);
        }

        return ['dealsHtml' => $dealsHtml, 'productsHtml' => $productsHtml];
    }
    
    public function getDealProductsAction($dealId, $stageId = null, $productSearchQuery = null)
    {
        Loader::includeModule('crm');
        Loader::includeModule('iblock');
        
        $productRowsUnfiltered = ProductRowTable::getList([
            'select' => ['PRODUCT_ID', 'PRODUCT_NAME', 'PRICE', 'QUANTITY'],
            'filter' => ['=OWNER_TYPE' => 'D', '=OWNER_ID' => $dealId],
        ])->fetchAll();

        $productRowsUnfiltered = array_filter($productRowsUnfiltered, function($row) {
            return !in_array($row['PRODUCT_ID'], $this->excludedProductIds);
        });
        
        if (empty($productRowsUnfiltered)) {
            return ['html' => $this->renderItems([], 'deal_product', $stageId)];
        }
        
        $allIndividualProductIdsInDeal = array_unique(array_column($productRowsUnfiltered, 'PRODUCT_ID'));
        $productsSetInfo = $this->getProductsSetInfo($allIndividualProductIdsInDeal);
        
        $aggregatedItems = $this->aggregateProducts($productRowsUnfiltered, $productsSetInfo);
        
        if (!empty($productSearchQuery)) {
            $searchQuery = trim($productSearchQuery);
            $finalItems = [];
            foreach ($aggregatedItems as $item) {
                if (mb_stripos($item['PRODUCT_NAME'], $searchQuery) !== false) {
                    $finalItems[] = $item;
                }
            }
            $aggregatedItems = $finalItems;
        }
        
        $finalAggregatedProductIds = array_column($aggregatedItems, 'PRODUCT_ID');
        $productProperties = $this->getProductsProperties($finalAggregatedProductIds);
        
        $allIdsForQuantities = $allIndividualProductIdsInDeal;
        $setIdsToLookup = [];

        $referencedSetIds = array_unique(array_filter(array_column($productsSetInfo, 'SET_ID')));
        if (!empty($referencedSetIds)) {
            $setIdsToLookup = array_merge($setIdsToLookup, $referencedSetIds);
        }

        foreach ($aggregatedItems as $p) {
            if ($p['IS_SET']) {
                $setIdsToLookup[] = $p['PRODUCT_ID'];
            }
        }
        $setIdsToLookup = array_unique($setIdsToLookup);

        if (!empty($setIdsToLookup)) {
            $dbRes = \CIBlockElement::GetList([], ['IBLOCK_ID' => 23, '=PROPERTY_170' => $setIdsToLookup], false, false, ['ID']);
            while ($product = $dbRes->Fetch()) {
                $allIdsForQuantities[] = (int)$product['ID'];
            }
        }
        
        $allIdsForQuantities = array_merge($allIdsForQuantities, $setIdsToLookup);
        $allIdsForQuantities = array_unique($allIdsForQuantities);

        $productsSetInfo = $this->getProductsSetInfo($allIdsForQuantities);
        $readyForShipmentQuantities = $this->getProductReadyForShipmentQuantities($allIdsForQuantities);
        $pipelineQuantitiesIndividual = $this->getProductQuantitiesByWarehouse($allIdsForQuantities, null);
        // NEW: Get quantities for the "Сборка" column
        $assemblyPipelineQuantities = $this->getProductQuantitiesOnStages($allIdsForQuantities, ['31', '28', '21', '27']);
        
        $this->getFilterWarehouseFieldInfo();
        $yuzhnyPortId = null;
        $ramenskiyId = null;
        if ($this->filterWarehouses) {
            foreach ($this->filterWarehouses as $warehouse) {
                if (mb_strpos($warehouse['VALUE'], 'Южнопортовый') !== false) {
                    $yuzhnyPortId = $warehouse['ID'];
                }
                if (mb_strpos($warehouse['VALUE'], 'Раменский') !== false) {
                    $ramenskiyId = $warehouse['ID'];
                }
            }
        }

        foreach ($aggregatedItems as &$product) {
            $elementId = $product['PRODUCT_ID'];
            if (isset($productProperties[$elementId])) {
                $product = array_merge($product, $productProperties[$elementId]);
            }
            
            $readyQty = 0;
            $assemblyQty = 0;
            $deliveryQty = 0;
            $pickupQty = 0;
            $assembledQty = 0;
            $sborkaQty = 0; // For new column
            $readyQtyByWarehouse = [];

            if ($product['IS_SET']) {
                foreach ($productsSetInfo as $individualId => $setInfo) {
                    if (isset($setInfo['SET_ID']) && $setInfo['SET_ID'] == $elementId) {
                        $assemblyQty += $pipelineQuantitiesIndividual[$individualId]['assembly'] ?? 0;
                        $deliveryQty += $pipelineQuantitiesIndividual[$individualId]['delivery'] ?? 0;
                        $pickupQty += $pipelineQuantitiesIndividual[$individualId]['pickup'] ?? 0;
                        $assembledQty += $pipelineQuantitiesIndividual[$individualId]['assembled'] ?? 0;
                        $sborkaQty += $assemblyPipelineQuantities[$individualId] ?? 0;

                        $componentReadyInfo = $readyForShipmentQuantities[$individualId] ?? ['total' => 0, 'by_warehouse' => []];
                        $readyQty += $componentReadyInfo['total'];
                        foreach($componentReadyInfo['by_warehouse'] as $whId => $whQty) {
                            if(!isset($readyQtyByWarehouse[$whId])) $readyQtyByWarehouse[$whId] = 0;
                            $readyQtyByWarehouse[$whId] += $whQty;
                        }
                    }
                }
                if (isset($pipelineQuantitiesIndividual[$elementId])) {
                    $assemblyQty += $pipelineQuantitiesIndividual[$elementId]['assembly'] ?? 0;
                    $deliveryQty += $pipelineQuantitiesIndividual[$elementId]['delivery'] ?? 0;
                    $pickupQty += $pipelineQuantitiesIndividual[$elementId]['pickup'] ?? 0;
                    $assembledQty += $pipelineQuantitiesIndividual[$elementId]['assembled'] ?? 0;
                }
                 if (isset($assemblyPipelineQuantities[$elementId])) {
                     $sborkaQty += $assemblyPipelineQuantities[$elementId];
                }
                $setReadyInfo = $readyForShipmentQuantities[$elementId] ?? ['total' => 0, 'by_warehouse' => []];
                $readyQty += $setReadyInfo['total'];
                foreach($setReadyInfo['by_warehouse'] as $whId => $whQty) {
                    if(!isset($readyQtyByWarehouse[$whId])) $readyQtyByWarehouse[$whId] = 0;
                    $readyQtyByWarehouse[$whId] += $whQty;
                }
            } else {
                if (isset($pipelineQuantitiesIndividual[$elementId])) {
                    $assemblyQty = $pipelineQuantitiesIndividual[$elementId]['assembly'] ?? 0;
                    $deliveryQty = $pipelineQuantitiesIndividual[$elementId]['delivery'] ?? 0;
                    $pickupQty = $pipelineQuantitiesIndividual[$elementId]['pickup'] ?? 0;
                    $assembledQty = $pipelineQuantitiesIndividual[$elementId]['assembled'] ?? 0;
                }
                $sborkaQty = $assemblyPipelineQuantities[$elementId] ?? 0;

                $productReadyInfo = $readyForShipmentQuantities[$elementId] ?? ['total' => 0, 'by_warehouse' => []];
                $readyQty = $productReadyInfo['total'];
                $readyQtyByWarehouse = $productReadyInfo['by_warehouse'];
            }
        
            $product['READY_FOR_SHIPMENT_QTY'] = $readyQty;
            $product['ASSEMBLY_QTY'] = $assemblyQty;
            $product['DELIVERY_QTY'] = $deliveryQty;
            $product['PICKUP_QTY'] = $pickupQty;
            $product['ASSEMBLED_QTY'] = $assembledQty;
            $product['SBORKA_QTY'] = $sborkaQty; // For new column
            
            $yuzhnyPortStock = (float)($product['PROPERTY_135_VALUE'] ?? 0);
            $ramenskiyStock = (float)($product['PROPERTY_145_VALUE'] ?? 0);
            
            $readyYuzhny = $yuzhnyPortId ? ($readyQtyByWarehouse[$yuzhnyPortId] ?? 0) : 0;
            $readyRamenskiy = $ramenskiyId ? ($readyQtyByWarehouse[$ramenskiyId] ?? 0) : 0;
            $product['READY_QTY_YUZHNY'] = $readyYuzhny;
            $product['READY_QTY_RAMENSKIY'] = $readyRamenskiy;

            $product['FREE_STOCK_YUZHNY'] = $yuzhnyPortStock - $readyYuzhny;
            $product['FREE_STOCK_RAMENSKIY'] = $ramenskiyStock - $readyRamenskiy;
        }
        unset($product);
        
        usort($aggregatedItems, function($a, $b) {
            $qtyA = $a['TOTAL_QUANTITY'] ?? $a['QUANTITY'] ?? 0;
            $qtyB = $b['TOTAL_QUANTITY'] ?? $b['QUANTITY'] ?? 0;
            return $qtyB <=> $qtyA;
        });
        
        return ['html' => $this->renderItems($aggregatedItems, 'deal_product', $stageId)];
    }

    public function getDealsByProductAction($stageId, $sortBy, $sortOrder, $productId = null, $setId = null)
    {
        Loader::includeModule('crm');
        
        $filter = $this->getStageFilter($stageId);
        $finalDeals = [];

        $dealIdsWithItems = [];
        $productIdsForQty = [];

        if ($productId) {
            $productIdsForQty[] = (int)$productId;
            $rows = ProductRowTable::getList(['select' => ['OWNER_ID'], 'filter' => ['=OWNER_TYPE' => 'D', '=PRODUCT_ID' => $productId]])->fetchAll();
            $dealIdsWithItems = array_column($rows, 'OWNER_ID');
        } elseif ($setId) {
            $componentIds = [];
            $dbRes = \CIBlockElement::GetList([], ['IBLOCK_ID' => 23, '=PROPERTY_170' => $setId], false, false, ['ID']);
            while ($product = $dbRes->Fetch()) {
                $componentIds[] = (int)$product['ID'];
            }
            
            $productIdsForQty = $componentIds;
            $productIdsForQty[] = (int)$setId;
            $productIdsForQty = array_unique($productIdsForQty);

            if (!empty($productIdsForQty)) {
                $rows = ProductRowTable::getList(['select' => ['OWNER_ID'], 'filter' => ['=OWNER_TYPE' => 'D', '@PRODUCT_ID' => $productIdsForQty]])->fetchAll();
                $dealIdsWithItems = array_unique(array_column($rows, 'OWNER_ID'));
            }
        }

        if (!empty($dealIdsWithItems)) {
            $rows = ProductRowTable::getList([
                'select' => ['OWNER_ID', 'QUANTITY'],
                'filter' => [
                    '=OWNER_TYPE' => 'D',
                    '@OWNER_ID' => $dealIdsWithItems,
                    '@PRODUCT_ID' => $productIdsForQty
                ]
            ])->fetchAll();

            $quantitiesByDealId = [];
            foreach ($rows as $row) {
                if (!isset($quantitiesByDealId[$row['OWNER_ID']])) {
                    $quantitiesByDealId[$row['OWNER_ID']] = 0;
                }
                $quantitiesByDealId[$row['OWNER_ID']] += (float)$row['QUANTITY'];
            }

            $arOrder = ['MOVED_TIME' => 'ASC'];
            $arFilter = ['@ID' => $dealIdsWithItems, '@STAGE_ID' => $filter, 'CHECK_PERMISSIONS' => 'N'];
            $arSelect = ['ID', 'TITLE', 'MOVED_TIME', 'DATE_CREATE', 'UF_CRM_1738582841', 'UF_CRM_1755005612', 'UF_CRM_1755602273', 'COMMENTS', 'UF_CRM_1753786869', 'ASSIGNED_BY_ID'];
            
            $dealsResult = [];
            $dbRes = \CCrmDeal::GetListEx($arOrder, $arFilter, false, false, $arSelect);
            while ($deal = $dbRes->GetNext()) $dealsResult[] = $deal;

            $dealIds = array_column($dealsResult, 'ID');
            $chatInfo = $this->getDealsChatInfo($dealIds);
            $assemblyTimes = $this->getDealsAssemblyTime($dealIds);
            $closingDocs = $this->getClosingDocuments($dealIds);
            $totalWeights = $this->getDealsTotalWeight($dealIds);

            $assignedByIds = array_column($dealsResult, 'ASSIGNED_BY_ID');
            $usersInfo = $this->getUsersInfo($assignedByIds);

            foreach ($dealsResult as $deal) {
                $dealData = [
                    'ID' => $deal['ID'], 'TITLE' => $deal['TITLE'], 'QUANTITY' => $quantitiesByDealId[$deal['ID']] ?? 0,
                    'MOVED_TIME' => $deal['MOVED_TIME'], 'DATE_CREATE' => $deal['DATE_CREATE'],
                    'UF_CRM_1738582841' => $deal['UF_CRM_1738582841'], 'UF_CRM_1755005612' => $deal['UF_CRM_1755005612'],
                    'UF_CRM_1755602273' => $deal['UF_CRM_1755602273'],
                    'UF_CRM_1753786869' => $deal['UF_CRM_1753786869'],
                    'COMMENTS' => $deal['COMMENTS'],
                    'ASSIGNED_BY_ID' => $deal['ASSIGNED_BY_ID']
                ];
                if (isset($assemblyTimes[$deal['ID']])) $dealData['assemblyTime'] = $assemblyTimes[$deal['ID']];
                $assemblyTime = $dealData['assemblyTime'] ?? 0;
                $isOverdue = false;

                switch ($stageId) {
                    case '13_26':
                    case '17':
                        $isOverdue = $assemblyTime >= 20;
                        break;
                    case '25':
                    case 'FINAL_INVOICE':
                    case 'NEW':
                        $isOverdue = $assemblyTime >= 1;
                        break;
                    case '21_27':
                        $isOverdue = $assemblyTime >= 3;
                        break;
                    case '31':
                        $isOverdue = $assemblyTime >= 2;
                        break;
                    case '10':
                        $isOverdue = $assemblyTime >= 30;
                        break;
                    case '20':
                        $isOverdue = $assemblyTime > 60;
                        break;
                }

                if ($isOverdue) {
                    $dealData['isOverdue'] = true;
                }
                if (isset($chatInfo[$deal['ID']])) $dealData['chatInfo'] = $chatInfo[$deal['ID']];
                if (isset($totalWeights[$deal['ID']])) $dealData['totalWeight'] = $totalWeights[$deal['ID']];
                
                if (isset($closingDocs[$deal['ID']])) {
                    $dealData['closingDoc'] = $closingDocs[$deal['ID']];
                    $dealData['CLOSING_DOC_TITLE'] = implode(', ', array_column($closingDocs[$deal['ID']], 'TITLE'));
                     $dates = array_filter(array_column($closingDocs[$deal['ID']], 'DATE'));
                     if (!empty($dates)) {
                        $formattedDates = array_map(fn($date) => $date->format('d.m.Y'), $dates);
                        $dealData['CLOSING_DOC_DATE'] = implode(', ', array_unique($formattedDates));
                    } else {
                        $dealData['CLOSING_DOC_DATE'] = null;
                    }
                } else {
                    $dealData['CLOSING_DOC_TITLE'] = '';
                    $dealData['CLOSING_DOC_DATE'] = null;
                }
                $dealData['ASSIGNED_BY_NAME'] = $usersInfo[$dealData['ASSIGNED_BY_ID']] ?? '';

                $finalDeals[] = $dealData;
            }
            $this->applySorting($finalDeals, $sortBy, $sortOrder);
        }

        $this->getAssemblyStatusFieldInfo();
        $this->getShippingWarehouseFieldInfo();
        $this->getFilterWarehouseFieldInfo();
        return [ 'html' => $this->renderItems($finalDeals, 'deal_with_product_quantity', $stageId) ];
    }

    private function renderItems($items, $type, $stageId = null)
    {
        if (empty($items) && $type !== 'product_with_total_quantity') {
             return '<div class="dashboard-placeholder">Ничего не найдено</div>';
        }
        
        $html = '<table class="dashboard-table">';
        $counter = 1;

        $filterWarehousesMap = [];
        if ($this->filterWarehouses) {
            foreach ($this->filterWarehouses as $warehouse) {
                $filterWarehousesMap[$warehouse['ID']] = $warehouse['VALUE'];
            }
        }

        $isShipmentView = in_array($stageId, ['25', '17', '13_26']);
        $showShipmentStockCols = in_array($stageId, ['20', '31', '21_27', '28']);
        $showYuzhnyPort = true;
        $showRamenskiy = true;

        $html .= '<thead><tr>';
        $html .= '<th class="col-number" data-sort-key="ID">#</th>';

        $isDealList = ($type === 'deal');
        $isProductDealList = ($type === 'deal_with_product_quantity');
        $assemblyStatusStages = ['20', '31', '28', '21_27'];
        $showAssemblyStatus = in_array($stageId, $assemblyStatusStages);

        if ($type === 'product_with_total_quantity' || $type === 'deal_product') {
            $html .= '<th class="col-name" data-sort-key="PRODUCT_NAME">Название</th>';
            $html .= '<th class="col-quantity" data-sort-key="TOTAL_QUANTITY">Требуется</th>';
            if (in_array($stageId, ['20', '31', '21_27', '28'])) {
                 $html .= '<th class="col-property">На сборке</th>';
            }
            if ($showYuzhnyPort) {
                $html .= '<th class="col-property col-svob-yup">Своб. ЮП</th>';
            }
            if ($showRamenskiy) {
                $html .= '<th class="col-property col-svob-ram">Своб. Рам.</th>';
            }
            if ($isShipmentView) {
                $html .= '<th class="col-property" data-sort-key="ASSEMBLY_QTY">Сборка</th>';
                $html .= '<th class="col-property" data-sort-key="DELIVERY_QTY">Доставка</th>';
                $html .= '<th class="col-property" data-sort-key="PICKUP_QTY">Самовывоз</th>';
            }
            if ($showShipmentStockCols) {
                $html .= '<th class="col-property col-yuzhny">Южный порт</th>';
                $html .= '<th class="col-property col-ramenskiy">Раменский</th>';
            }
        } else {
            $html .= '<th class="col-name" data-sort-key="ID">ID</th>';
            $html .= '<th class="col-assigned" data-sort-key="ASSIGNED_BY_NAME">Ответственный</th>';
            if ($isProductDealList) $html .= '<th class="col-quantity" data-sort-key="QUANTITY">Нужно отгрузить</th>';
            
            if ($stageId === '10') {
                $html .= '<th class="col-date-create" data-sort-key="DATE_CREATE">Дата создания</th>';
            }

            if ($stageId === '17') $html .= '<th class="col-delivery-date" data-sort-key="UF_CRM_1738582841">Дата доставки</th>';

            if (in_array($stageId, ['17', '13_26', '15', '25'])) {
                $html .= '<th class="col-closing-doc" data-sort-key="CLOSING_DOC_TITLE">Закр. документ</th>';
                $html .= '<th class="col-total-weight" data-sort-key="totalWeight">Общий вес</th>';
                $html .= '<th class="col-doc-date" data-sort-key="CLOSING_DOC_DATE">Дата документа</th>';
                $html .= '<th class="col-shipping-warehouse" data-sort-key="UF_CRM_1755602273">Склад отгрузки Логистика</th>';
            }
            if (in_array($stageId, ['17', '13_26', '15', '25', '20', '28', '31', '21_27'])) {
                $html .= '<th class="col-shipping-warehouse" data-sort-key="UF_CRM_1753786869">Склад отгрузки</th>';
            }
            if ($stageId === '28') {
                $html .= '<th class="col-total-weight" data-sort-key="totalWeight">Общий вес</th>';
            }
            if (in_array($stageId, ['17', '13_26', '15', '25'])) {
                if(!$isShipmentView)
                    $html .= '<th class="col-comments" data-sort-key="COMMENTS">Комментарий</th>';
            }
            
            if ($showAssemblyStatus) $html .= '<th class="col-assembly-status" data-sort-key="UF_CRM_1755005612">Статус сборки</th>';
            $assemblyTimeHeader = 'Срок';
            $timeUnit = ' д.';
            $limit = '';
    
            switch ($stageId) {
                case '13_26':
                case '17':
                    $limit = ' (20' . $timeUnit . ')';
                    break;
                case '25':
                case 'FINAL_INVOICE':
                case 'NEW':
                    $limit = ' (1' . $timeUnit . ')';
                    break;
                case '21_27':
                    $limit = ' (3' . $timeUnit . ')';
                    break;
                case '31':
                    $limit = ' (2' . $timeUnit . ')';
                    break;
                case '10':
                    $limit = ' (30' . $timeUnit . ')';
                    break;
                case '20':
                    $timeUnit = ' мин.';
                    $limit = ' (60' . $timeUnit . ')';
                    break;
            }
            $assemblyTimeHeader .= $limit;
            $html .= '<th class="col-assembly-time" data-sort-key="assemblyTime">' . $assemblyTimeHeader . '</th>';
            if ($isDealList) $html .= '<th class="col-date" data-sort-key="MOVED_TIME">Дата переноса</th>';
            $html .= '<th class="col-chat">Чат</th>';
        }
        $html .= '</tr></thead>';

        $html .= '<tbody>';

        if ($type === 'product_with_total_quantity') {
            $html .= "<tr class='dashboard-list-item' data-product-id='all'>";
            $html .= "<td class='col-number'>-</td>";
            $html .= "<td class='col-name'><span class='item-name'><strong>Все товары</strong></span></td>";

            $emptyCellsCount = 1; // "Требуется"
            if (in_array($stageId, ['20', '31', '21_27', '28'])) $emptyCellsCount++;
            if ($showYuzhnyPort) $emptyCellsCount++;
            if ($showRamenskiy) $emptyCellsCount++;
            if ($isShipmentView) {
                $emptyCellsCount += 3;
            }
            if ($showShipmentStockCols) {
                $emptyCellsCount += 2;
            }
            $html .= str_repeat('<td></td>', $emptyCellsCount);
            $html .= "</tr>";
        }

        foreach ($items as $item) {
            $dataAttrs = '';
            if (!empty($item['ID']) && ($isDealList || $isProductDealList)) {
                $dataAttrs = "data-deal-id='{$item['ID']}' data-url='/crm/deal/details/{$item['ID']}/'";
            }
            
            if ($type === 'product_with_total_quantity' || $type === 'deal_product') {
                if (!empty($item['IS_SET']) && $item['IS_SET'] === true) {
                    $dataAttrs = "data-set-id='{$item['PRODUCT_ID']}' data-url='/crm/catalog/23/product/{$item['PRODUCT_ID']}/'";
                } elseif (!empty($item['PRODUCT_ID'])) {
                    $dataAttrs = "data-product-id='{$item['PRODUCT_ID']}' data-url='/crm/catalog/23/product/{$item['PRODUCT_ID']}/'";
                }
            }
            
            $rowClasses = ['dashboard-list-item'];
            $html .= "<tr class='" . implode(' ', $rowClasses) . "' {$dataAttrs}>";
            $html .= "<td class='col-number'>{$counter}.</td>";
            
            if ($type === 'product_with_total_quantity' || $type === 'deal_product') {
                $title = htmlspecialcharsbx($item['PRODUCT_NAME']);
                $requiredQuantity = (float)($item['TOTAL_QUANTITY'] ?? $item['QUANTITY']);
                $html .= "<td class='col-name'><span class='item-name'>{$title}</span></td>";
                $html .= "<td class='col-quantity'><strong>{$requiredQuantity}</strong> шт.</td>";
                if (in_array($stageId, ['20', '31', '21_27', '28'])) {
                     $html .= "<td class='col-property'><strong>" . ($item['SBORKA_QTY'] ?? '0') . "</strong> шт.</td>";
                }
                if ($showYuzhnyPort) {
                    $freeStockYuzhny = $item['FREE_STOCK_YUZHNY'] ?? 0;
                    $yuzhnyPortValue = (float)($item['PROPERTY_135_VALUE'] ?? 0);
                    $html .= "<td class='col-property col-svob-yup ".($freeStockYuzhny < 0 ? 'is-due-today' : '')."'><strong>" . $freeStockYuzhny . "</strong> шт. <span style='color: #828B95;'>(" . $yuzhnyPortValue . ")</span></td>";
                }
                if ($showRamenskiy) {
                    $freeStockRamenskiy = $item['FREE_STOCK_RAMENSKIY'] ?? 0;
                    $ramenskiyValue = (float)($item['PROPERTY_145_VALUE'] ?? 0);
                    $html .= "<td class='col-property col-svob-ram ".($freeStockRamenskiy < 0 ? 'is-due-today' : '')."'><strong>" . $freeStockRamenskiy . "</strong> шт. <span style='color: #828B95;'>(" . $ramenskiyValue . ")</span></td>";
                }
                if ($isShipmentView) {
                    $html .= "<td class='col-property'><strong>" . ($item['ASSEMBLY_QTY'] ?? '0') . "</strong> шт.</td>";
                    $html .= "<td class='col-property' data-stage-type='delivery'><strong>" . ($item['DELIVERY_QTY'] ?? '0') . "</strong> шт.</td>";
                    $html .= "<td class='col-property' data-stage-type='pickup'><strong>" . ($item['PICKUP_QTY'] ?? '0') . "</strong> шт.</td>";
                }
                if ($showShipmentStockCols) {
                    $html .= "<td class='col-property col-yuzhny'><strong>" . ($item['READY_QTY_YUZHNY'] ?? '0') . "</strong> шт.</td>";
                    $html .= "<td class='col-property col-ramenskiy'><strong>" . ($item['READY_QTY_RAMENSKIY'] ?? '0') . "</strong> шт.</td>";
                }
            } else {
                $title = htmlspecialcharsbx($item['ID']);
                $html .= "<td class='col-name'><span class='item-name'>{$title}</span></td>";

                $assignedName = htmlspecialcharsbx($item['ASSIGNED_BY_NAME'] ?? '');
                $html .= "<td class='col-assigned'>{$assignedName}</td>";

                if ($isProductDealList) $html .= "<td class='col-quantity'><strong>" . (float)$item['QUANTITY'] . "</strong> шт.</td>";
                
                if ($stageId === '10') {
                    $html .= "<td class='col-date-create'>" . (!empty($item['DATE_CREATE']) ? (new \Bitrix\Main\Type\DateTime($item['DATE_CREATE']))->format('d.m.Y H:i') : '') . "</td>";
                }

                if ($stageId === '17') $html .= "<td class='col-delivery-date'>" . (!empty($item['UF_CRM_1738582841']) ? (new \Bitrix\Main\Type\DateTime($item['UF_CRM_1738582841']))->format('d.m.Y') : '') . "</td>";
                
                if (in_array($stageId, ['17', '13_26', '15', '25'])) {
                    $docHtml = '';
                    $docDateHtml = '';
                    if (isset($item['closingDoc']) && is_array($item['closingDoc'])) {
                        $docLinks = [];
                        $docDates = [];
                        foreach ($item['closingDoc'] as $doc) {
                            $docLinks[] = "<a href='#' data-slider-url='/crm/type/1056/details/{$doc['ID']}/' class='item-closing-doc'>" . htmlspecialcharsbx($doc['TITLE']) . "</a>";
                            if (!empty($doc['DATE'])) {
                                $docDates[] = $doc['DATE']->format('d.m.Y');
                            }
                        }
                        $docHtml = implode(', ', $docLinks);
                        if (!empty($docDates)) {
                            $docDateHtml = implode(', ', array_unique($docDates));
                        }
                    }

                    $html .= "<td class='col-closing-doc'>{$docHtml}</td>";
                    
                    $totalWeightHtml = '';
                    if (isset($item['totalWeight']) && $item['totalWeight'] > 0) {
                        $totalWeightHtml = '<strong>' . round($item['totalWeight'], 2) . '</strong> кг';
                    }
                    $html .= "<td class='col-total-weight'>{$totalWeightHtml}</td>";

                    $html .= "<td class='col-doc-date'>{$docDateHtml}</td>";

                    $currentWarehouseId = $item['UF_CRM_1755602273'] ?? '';
                    $selectHtml = "<select class='shipping-warehouse-select' data-deal-id='{$item['ID']}'><option value=''>-</option>";
                    if ($this->shippingWarehouses) {
                        foreach ($this->shippingWarehouses as $warehouse) {
                            $selected = ($warehouse['ID'] == $currentWarehouseId) ? 'selected' : '';
                            $selectHtml .= "<option value='{$warehouse['ID']}' {$selected}>" . htmlspecialcharsbx($warehouse['VALUE']) . "</option>";
                        }
                    }
                    $selectHtml .= "</select>";
                    $html .= "<td class='col-shipping-warehouse'>{$selectHtml}</td>";
                }
                if (in_array($stageId, ['17', '13_26', '15', '25', '20', '28', '31', '21_27'])) {
                    $filterWarehouseId = $item['UF_CRM_1753786869'] ?? '';
                    $filterWarehouseValue = isset($filterWarehousesMap[$filterWarehouseId]) ? htmlspecialcharsbx($filterWarehousesMap[$filterWarehouseId]) : '';
                    $html .= "<td class='col-shipping-warehouse'>{$filterWarehouseValue}</td>";
                }
                if ($stageId === '28') {
                    $totalWeightHtml = '';
                    if (isset($item['totalWeight']) && $item['totalWeight'] > 0) {
                        $totalWeightHtml = '<strong>' . round($item['totalWeight'], 2) . '</strong> кг';
                    }
                    $html .= "<td class='col-total-weight'>{$totalWeightHtml}</td>";
                }
                if (in_array($stageId, ['17', '13_26', '15', '25'])) {
                    $commentsText = '';
                    if (!empty($item['COMMENTS'])) {
                        $parser = new \CTextParser();
                        $htmlContent = $parser->convertText($item['COMMENTS']);
                        $commentsText = trim(html_entity_decode(strip_tags($htmlContent), ENT_QUOTES, 'UTF-8'));
                    }
                    $shortComments = TruncateText($commentsText, 50);
                    $fullCommentsAttr = htmlspecialcharsbx($commentsText);
                    if(!$isShipmentView)
                        $html .= "<td class='col-comments'><span title='{$fullCommentsAttr}'>{$shortComments}</span></td>";
                }

                if ($showAssemblyStatus) {
                    $currentStatusId = $item['UF_CRM_1755005612'] ?? '';
                    $selectHtml = "<select class='assembly-status-select' data-deal-id='{$item['ID']}'><option value=''>-</option>";
                    if ($this->assemblyStatuses) {
                        foreach ($this->assemblyStatuses as $status) {
                            $selected = ($status['ID'] == $currentStatusId) ? 'selected' : '';
                            $selectHtml .= "<option value='{$status['ID']}' {$selected}>" . htmlspecialcharsbx($status['VALUE']) . "</option>";
                        }
                    }
                    $selectHtml .= "</select>";
                    $html .= "<td class='col-assembly-status'>{$selectHtml}</td>";
                }
                $assemblyTimeUnit = ($stageId === '20') ? ' мин.' : ' д.';
                $assemblyTimeClass = '';
                if (!empty($item['isOverdue'])) {
                    $assemblyTimeClass = ' is-due-today';
                }
                $html .= "<td class='col-assembly-time{$assemblyTimeClass}'>" . (isset($item['assemblyTime']) ? $item['assemblyTime'] . $assemblyTimeUnit : '') . "</td>";
                if ($isDealList) $html .= "<td class='col-date'>" . (!empty($item['MOVED_TIME']) ? (new \Bitrix\Main\Type\DateTime($item['MOVED_TIME']))->format('d.m.Y H:i') : '') . "</td>";
                $chatCell = "<span class='item-chat-icon is-add' data-deal-id-for-chat='{$item['ID']}' title='Создать чат'></span>";
                if (isset($item['chatInfo'])) {
                    $isUnreadClass = $item['chatInfo']['isUnread'] ? 'is-unread' : '';
                    $isMemberAttr = $item['chatInfo']['isMember'] ? 'true' : 'false';
                    $chatCell = "<span class='item-chat-icon {$isUnreadClass}' data-chat-id='{$item['chatInfo']['chatId']}' data-is-member='{$isMemberAttr}' title='Открыть чат'></span>";
                }
                $html .= "<td class='col-chat'>{$chatCell}</td>";
            }
            $html .= '</tr>';
            $counter++;
        }
        $html .= '</tbody></table>';
        if (empty($items) && $type === 'product_with_total_quantity') {
             return '<div class="dashboard-placeholder">Ничего не найдено</div>';
        }

        return $html;
    }


    private function getAssemblyStatusFieldInfo()
    {
        if ($this->assemblyStatuses !== null) return;
        $this->assemblyStatuses = [];
        $userField = \CUserTypeEntity::GetList([], ['ENTITY_ID' => 'CRM_DEAL', 'FIELD_NAME' => 'UF_CRM_1755005612'])->Fetch();
        if ($userField) {
            $enum = new \CUserFieldEnum();
            $rsEnum = $enum->GetList([], ['USER_FIELD_ID' => $userField['ID']]);
            while ($arEnum = $rsEnum->GetNext()) $this->assemblyStatuses[] = ['ID' => $arEnum['ID'], 'VALUE' => $arEnum['VALUE']];
        }
    }

    private function getShippingWarehouseFieldInfo()
    {
        if ($this->shippingWarehouses !== null) return;
        $this->shippingWarehouses = [];
        $userField = \CUserTypeEntity::GetList([], ['ENTITY_ID' => 'CRM_DEAL', 'FIELD_NAME' => 'UF_CRM_1755602273'])->Fetch();
        if ($userField) {
            $enum = new \CUserFieldEnum();
            $rsEnum = $enum->GetList([], ['USER_FIELD_ID' => $userField['ID']]);
            while ($arEnum = $rsEnum->GetNext()) {
                $this->shippingWarehouses[] = ['ID' => $arEnum['ID'], 'VALUE' => $arEnum['VALUE']];
            }
        }
    }
    
    private function getFilterWarehouseFieldInfo()
    {
        if ($this->filterWarehouses !== null) return;
        $this->filterWarehouses = [];
        $userField = \CUserTypeEntity::GetList([], ['ENTITY_ID' => 'CRM_DEAL', 'FIELD_NAME' => 'UF_CRM_1753786869'])->Fetch();
        if ($userField) {
            $enum = new \CUserFieldEnum();
            $rsEnum = $enum->GetList([], ['USER_FIELD_ID' => $userField['ID']]);
            while ($arEnum = $rsEnum->GetNext()) {
                $this->filterWarehouses[] = ['ID' => $arEnum['ID'], 'VALUE' => $arEnum['VALUE']];
            }
        }
    }
    
    public function executeComponent()
    {
        if (Loader::includeModule('crm')) {
            $this->getAssemblyStatusFieldInfo();
            $this->getShippingWarehouseFieldInfo();
            $this->getFilterWarehouseFieldInfo();
            $this->arResult['ASSEMBLY_STATUSES'] = $this->assemblyStatuses;
            $this->arResult['SHIPPING_WAREHOUSES'] = $this->shippingWarehouses;
            $this->arResult['FILTER_WAREHOUSES'] = $this->filterWarehouses;
            $this->arResult['SORT_OPTIONS'] = CUserOptions::GetOption('company.deal.dashboard', 'sort_options', []);

            $statusResult = StatusTable::getList(['filter' => ['=ENTITY_ID' => 'DEAL_STAGE'], 'select' => ['STATUS_ID', 'NAME', 'COLOR', 'SORT', 'SYSTEM'], 'order' => ['SORT' => 'ASC']]);
            $allStagesFromCrm = [];
            while ($status = $statusResult->fetch()) {
                $allStagesFromCrm[$status['STATUS_ID']] = ['ID' => $status['STATUS_ID'], 'NAME' => $status['NAME'], 'COLOR' => $status['COLOR']];
            }
            $this->arResult['STAGE_INFO_FOR_JS'] = $allStagesFromCrm;

            $stagesToQuery = array_keys($allStagesFromCrm);
            $dealsRaw = DealTable::getList(['select' => ['STAGE_ID'], 'filter' => ['@STAGE_ID' => $stagesToQuery]])->fetchAll();
            $dealCounts = array_count_values(array_column($dealsRaw, 'STAGE_ID'));
            
            $finalStages = [];
            $excludedStages = ['WON', 'LOSE', '19', '30'];
            $pickupStages = ['13', '26'];
            $purchaseStages = ['21', '27'];
            $pickupStageAdded = false;
            $purchaseStageAdded = false;

            foreach ($allStagesFromCrm as $stageId => $stageInfo) {
                if (in_array($stageId, $excludedStages)) continue;
                if (in_array($stageId, $pickupStages)) {
                    if (!$pickupStageAdded) {
                        $pickupCount = ($dealCounts['13'] ?? 0) + ($dealCounts['26'] ?? 0);
                        $finalStages['13_26'] = ['ID' => '13_26', 'NAME' => 'Самовывоз', 'COUNT' => $pickupCount];
                        $pickupStageAdded = true;
                    }
                    continue;
                }
                if (in_array($stageId, $purchaseStages)) {
                    if (!$purchaseStageAdded) {
                        $purchaseCount = ($dealCounts['21'] ?? 0) + ($dealCounts['27'] ?? 0);
                        $finalStages['21_27'] = ['ID' => '21_27', 'NAME' => 'Закупка товара', 'COUNT' => $purchaseCount];
                        $purchaseStageAdded = true;
                    }
                    continue;
                }
                $finalStages[$stageId] = ['ID' => $stageId, 'NAME' => $stageInfo['NAME'], 'COUNT' => (int)($dealCounts[$stageId] ?? 0)];
            }
            $this->arResult['STAGES'] = $finalStages;
        }
        $this->arResult['HEIGHTS'] = CUserOptions::GetOption('company.deal.dashboard', 'window_heights', ['deals' => 200, 'products' => 200, 'details' => 250]);
        $this->includeComponentTemplate();
    }

    /**
     * Agent function to automatically move deals from stage 20 to 25.
     * To use it, create a new agent in the admin panel with the function name:
     * CompanyDealDashboardComponent::autoMoveDealsAgent();
     * @return string The name of the agent function for Bitrix.
     */
    public function moveDealsToStage25()
    {
        require_once($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/crest/crest.php');
        $dealsToMove = $this->getDealsToMoveFromStage20();

        if (!empty($dealsToMove)) {
            //$dealUpdater = new \CCrmDeal(false);
            foreach ($dealsToMove as $deal) {
                $result = CRest::call(
                    'crm.deal.update',
                    [
                        'id' => $deal['ID'],
                        'fields' => [
                           'STAGE_ID' => '25'
                        ]
                    ]
                );
            }
        }

    }

    public function moveDealsFromStage20()
    {
        require_once($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/crest/crest.php');
        $dealsToMove = $this->getDealsForAutoMove();

        if (!empty($dealsToMove['to_stage_25'])) {
            foreach ($dealsToMove['to_stage_25'] as $deal) {
                $result = CRest::call(
                    'crm.deal.update',
                    [
                        'id' => $deal['ID'],
                        'fields' => [
                           'STAGE_ID' => '25'
                        ]
                    ]
                );
            }
        }
        if (!empty($dealsToMove['to_stage_28'])) {
            foreach ($dealsToMove['to_stage_28'] as $deal) {
                $result = CRest::call(
                    'crm.deal.update',
                    [
                        'id' => $deal['ID'],
                        'fields' => [
                           'STAGE_ID' => '28'
                        ]
                    ]
                );
            }
        }
    }

    public function getDealsForAutoMove()
    {
        if (!\Bitrix\Main\Loader::includeModule('crm') || !\Bitrix\Main\Loader::includeModule('iblock') || !\Bitrix\Main\Loader::includeModule('catalog')) {
            error_log("autoMoveDealsAgent: Required modules (crm, iblock, catalog) are not installed.");
            return [];
        }

        $this->getFilterWarehouseFieldInfo();
        $yuzhnyPortId = null;
        $ramenskiyId = null;
        if ($this->filterWarehouses) {
            foreach ($this->filterWarehouses as $warehouse) {
                if (mb_strpos($warehouse['VALUE'], 'Южнопортовый') !== false) {
                    $yuzhnyPortId = $warehouse['ID'];
                }
                if (mb_strpos($warehouse['VALUE'], 'Раменский') !== false) {
                    $ramenskiyId = $warehouse['ID'];
                }
            }
        }

        $dbDeals = \CCrmDeal::GetListEx(
            [],
            ['STAGE_ID' => '20', 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'TITLE', 'UF_CRM_1753786869']
        );

        $deals = [];
        while ($deal = $dbDeals->Fetch()) {
            $deals[] = $deal;
        }

        if (empty($deals)) {
            return [];
        }

        $dealProductsMap = [];
        $allProductIds = [];

        $dealIds = array_column($deals, 'ID');
        $productRows = \Bitrix\Crm\ProductRowTable::getList([
            'filter' => ['=OWNER_TYPE' => 'D', '@OWNER_ID' => $dealIds]
        ])->fetchAll();

        foreach ($productRows as $row) {
            $dealProductsMap[$row['OWNER_ID']][] = $row;
        }

        $flatProductsByDeal = [];
        foreach ($dealIds as $dealId) {
            $flatProductsByDeal[$dealId] = [];
            if (!empty($dealProductsMap[$dealId])) {
                $flatProductsByDeal[$dealId] = $this->getFlatProductsList($dealProductsMap[$dealId]);
                foreach ($flatProductsByDeal[$dealId] as $product) {
                    $allProductIds[] = $product['PRODUCT_ID'];
                }
            }
        }
        
        $uniqueProductIds = array_unique($allProductIds);

        $idsForQueries = $uniqueProductIds;
        if(empty($idsForQueries)) {
             $idsForQueries = [-1];
        }
        
        $productProperties = $this->getProductsProperties($idsForQueries);
        $readyForShipmentQuantities = $this->getProductReadyForShipmentQuantities($idsForQueries);
        $assemblyQuantities = $this->getProductQuantitiesOnStages($idsForQueries, ['31', '28', '21', '27']);

        $dealsToMove = [
            'to_stage_25' => [],
            'to_stage_28' => []
        ];

        foreach ($deals as $deal) {
            $dealId = $deal['ID'];
            $assignedWarehouseId = $deal['UF_CRM_1753786869'];

            if (!$assignedWarehouseId || !in_array($assignedWarehouseId, [$yuzhnyPortId, $ramenskiyId])) {
                continue;
            }

            $productsToCheck = $flatProductsByDeal[$dealId] ?? [];
            
            if (empty($productsToCheck)) {
                $dealsToMove['to_stage_25'][] = ['ID' => $deal['ID'], 'TITLE' => $deal['TITLE']];
                continue;
            }

            // --- Check 1: Assigned Warehouse ---
            $isStockSufficientOnAssigned = true;
            foreach ($productsToCheck as $productInfo) {
                $productId = $productInfo['PRODUCT_ID'];
                $quantityNeeded = $productInfo['QUANTITY'];
                
                $stockPropertyKey = ($assignedWarehouseId == $yuzhnyPortId) ? 'PROPERTY_135_VALUE' : 'PROPERTY_145_VALUE';
                
                $totalStock = (float)($productProperties[$productId][$stockPropertyKey] ?? 0);
                $reservedStock = (float)($readyForShipmentQuantities[$productId]['by_warehouse'][$assignedWarehouseId] ?? 0);
                $freeStock = $totalStock - $reservedStock;
                
                $assemblyQty = $assemblyQuantities[$productId] ?? 0;
                
                if (($freeStock - $assemblyQty) < $quantityNeeded) {
                    $isStockSufficientOnAssigned = false;
                    break;
                }
            }

            if ($isStockSufficientOnAssigned) {
                $dealsToMove['to_stage_25'][] = ['ID' => $deal['ID'], 'TITLE' => $deal['TITLE']];
                continue;
            }

            // --- Check 2: Other Warehouse ---
            $otherWarehouseId = ($assignedWarehouseId == $yuzhnyPortId) ? $ramenskiyId : $yuzhnyPortId;
            if (!$otherWarehouseId) {
                continue;
            }

            $isStockSufficientOnOther = true;
            foreach ($productsToCheck as $productInfo) {
                $productId = $productInfo['PRODUCT_ID'];
                $quantityNeeded = $productInfo['QUANTITY'];

                $stockPropertyKey = ($otherWarehouseId == $yuzhnyPortId) ? 'PROPERTY_135_VALUE' : 'PROPERTY_145_VALUE';

                $totalStock = (float)($productProperties[$productId][$stockPropertyKey] ?? 0);
                $reservedStock = (float)($readyForShipmentQuantities[$productId]['by_warehouse'][$otherWarehouseId] ?? 0);
                $freeStock = $totalStock - $reservedStock;

                $assemblyQty = $assemblyQuantities[$productId] ?? 0;

                if (($freeStock - $assemblyQty) < $quantityNeeded) {
                    $isStockSufficientOnOther = false;
                    break;
                }
            }

            if ($isStockSufficientOnOther) {
                $dealsToMove['to_stage_28'][] = ['ID' => $deal['ID'], 'TITLE' => $deal['TITLE']];
            }
        }

        return $dealsToMove;
    }


    /**
     * Helper function to get a "flat" list of products from a deal,
     * breaking down sets into their components.
     * @param array $productRows Products from ProductRowTable.
     * @return array A flat list of products like [['PRODUCT_ID' => X, 'QUANTITY' => Y], ...].
     */
    private function getFlatProductsList(array $productRows)
    {
        $flatList = [];
        $productQuantities = [];

        foreach ($productRows as $row) {
            $productId = $row['PRODUCT_ID'];
            $quantity = (float)$row['QUANTITY'];

            if (in_array($productId, $this->excludedProductIds)) {
                continue;
            }

            $sets = \CCatalogProductSet::getAllSetsByProduct($productId, \CCatalogProductSet::TYPE_SET);

            if ($sets) { // This is a product set
                foreach ($sets as $set) {
                    foreach ($set['ITEMS'] as $item) {
                        $componentId = $item['ITEM_ID'];
                        
                        if (in_array($componentId, $this->excludedProductIds)) {
                            continue;
                        }

                        $componentQuantity = (float)$item['QUANTITY'];
                        $totalComponentQuantity = $componentQuantity * $quantity;

                        if (!isset($productQuantities[$componentId])) {
                            $productQuantities[$componentId] = 0;
                        }
                        $productQuantities[$componentId] += $totalComponentQuantity;
                    }
                }
            } else { // This is a simple product
                if (!isset($productQuantities[$productId])) {
                    $productQuantities[$productId] = 0;
                }
                $productQuantities[$productId] += $quantity;
            }
        }

        foreach ($productQuantities as $id => $qty) {
            $flatList[] = ['PRODUCT_ID' => $id, 'QUANTITY' => $qty];
        }
        
        return $flatList;
    }
}