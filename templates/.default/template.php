<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

\Bitrix\Main\UI\Extension::load(["ui.buttons", "ui.alerts", "ui.spinner", "ui.forms"]);

$heights = $arResult['HEIGHTS'];

if (!empty($arResult['STAGE_INFO_FOR_JS'])) {
    echo '<script>window.DEAL_DASHBOARD_STAGE_INFO = ' . \CUtil::PhpToJSObject($arResult['STAGE_INFO_FOR_JS']) . ';</script>';
}
if (!empty($arResult['ASSEMBLY_STATUSES'])) {
    echo '<script>window.DEAL_DASHBOARD_ASSEMBLY_STATUSES = ' . \CUtil::PhpToJSObject($arResult['ASSEMBLY_STATUSES']) . ';</script>';
}
// Передаем настройки сортировки в JS
if (!empty($arResult['SORT_OPTIONS'])) {
    echo '<script>window.DEAL_DASHBOARD_SORT_OPTIONS = ' . \CUtil::PhpToJSObject($arResult['SORT_OPTIONS']) . ';</script>';
} else {
    echo '<script>window.DEAL_DASHBOARD_SORT_OPTIONS = {};</script>';
}
?>

<div class="deal-dashboard-container" id="deal-dashboard">
    <div class="dashboard-left-panel">
        <!-- Block for filters -->
        <div class="dashboard-block">
            <div class="dashboard-global-actions">
                <div class="ui-ctl ui-ctl-textbox ui-ctl-w-100">
                    <input type="text" class="ui-ctl-element" id="deal-search-input" placeholder="Поиск по ID сделки...">
                </div>
                <div class="ui-ctl ui-ctl-textbox ui-ctl-w-100 ui-ctl-after-icon" style="margin-left: 0;">
                    <button class="ui-ctl-after ui-ctl-icon-clear" id="product-search-reset" style="display: none;"></button>
                    <input type="text" class="ui-ctl-element" id="product-search-input" placeholder="Поиск по товару...">
                </div>
                <?php if (!empty($arResult['FILTER_WAREHOUSES'])): ?>
                    <div class="ui-ctl ui-ctl-select ui-ctl-w-100" style="margin-left: 0">
                        <select class="ui-ctl-element" id="warehouse-filter-select">
                            <option value="">Все склады отгрузки</option>
                            <option value="0">Не выбрано</option>
                            <?php foreach ($arResult['FILTER_WAREHOUSES'] as $warehouse): ?>
                                <option value="<?= htmlspecialcharsbx($warehouse['ID']) ?>"><?= htmlspecialcharsbx($warehouse['VALUE']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- Block for stages -->
        <div class="dashboard-block">
            <div class="ui-entity-sidebar-title">Стадии сделок</div>
            <ul class="dashboard-stages-list">
                <?php foreach ($arResult['STAGES'] as $stage): ?>
                    <li class="dashboard-stage-item" data-stage-id="<?= htmlspecialcharsbx($stage['ID']) ?>">
                        <span class="stage-name"><?= htmlspecialcharsbx($stage['NAME']) ?></span>
                        <span class="stage-counter-wrapper">(<span class="stage-counter"><?= $stage['COUNT'] ?></span>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="dashboard-right-panel">
        <div class="dashboard-block">
            <div class="dashboard-block-header">
                <div class="ui-entity-sidebar-title">Все товары на стадии</div>
                <button id="download-products-csv" class="ui-btn ui-btn-light-border ui-btn-xs" style="display: none;">
                    Скачать
                </button>
            </div>
            <div id="product-list-container" class="dashboard-list-container" data-table-key="products" style="height: <?= (int)$heights['products'] ?>px;">
                <div class="dashboard-placeholder">Выберите стадию слева</div>
            </div>
        </div>

        <div class="dashboard-resizer" data-resizes="product-list-container"></div>

        <div class="dashboard-block">
            <div class="dashboard-block-header">
                <div class="ui-entity-sidebar-title">Заказы на стадии</div>
                <div id="deal-actions-container" class="dashboard-deal-actions"></div>
            </div>
            <div id="deal-list-container" class="dashboard-list-container" data-table-key="deals" style="height: <?= (int)$heights['deals'] ?>px;">
                <div class="dashboard-placeholder">Выберите стадию слева</div>
            </div>
        </div>

        <div class="dashboard-resizer" data-resizes="deal-list-container"></div>

        <div class="dashboard-block dashboard-details-block">
            <div class="ui-entity-sidebar-title">Товары заказа</div>
            <div id="details-container" class="dashboard-list-container" data-table-key="details" style="height: <?= (int)$heights['details'] ?>px;">
                <div class="dashboard-placeholder">Выберите заказ</div>
            </div>
        </div>
    </div>
</div>
