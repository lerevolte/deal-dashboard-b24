BX.ready(function() {
    const dashboard = document.getElementById('deal-dashboard');
    if (!dashboard) return;

    const dealListContainer = document.getElementById('deal-list-container');
    const productListContainer = document.getElementById('product-list-container');
    const detailsContainer = document.getElementById('details-container');
    const dealActionsContainer = document.getElementById('deal-actions-container');
    const searchInput = document.getElementById('deal-search-input');
    const productSearchInput = document.getElementById('product-search-input');
    const productSearchReset = document.getElementById('product-search-reset');
    const downloadCsvBtn = document.getElementById('download-products-csv');
    const warehouseFilterSelect = document.getElementById('warehouse-filter-select');
    
    let activeStageItem = null;
    let activeDealItem = null;
    let activeProductItem = null;
    let sortOptions = window.DEAL_DASHBOARD_SORT_OPTIONS || {};
    let saveHeightsTimeout;
    let initialStageCounts = {};

    let currentFilter = {
        type: 'none',
        value: null,
        warehouseId: null,
    };

    dashboard.querySelectorAll('.dashboard-stage-item').forEach(item => {
        const stageId = item.dataset.stageId;
        const count = item.querySelector('.stage-counter').textContent;
        initialStageCounts[stageId] = count;
    });

    /**
     * Подсветка ячеек со статусом сборки, если день совпадает с текущим.
     */
    function highlightDueTodayCells() {
        const dayMap = { 'Вс': 0, 'Пн': 1, 'Вт': 2, 'Ср': 3, 'Чт': 4, 'Пт': 5, 'Сб': 6 };
        // JS getDay(): Sunday - 0, Monday - 1, ..., Saturday - 6
        const todayIndex = new Date().getDay();

        dashboard.querySelectorAll('.assembly-status-select').forEach(select => {
            const cell = select.closest('td');
            if (!cell) return;

            // Сначала сбрасываем класс
            cell.classList.remove('is-due-today');

            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption) {
                const selectedText = selectedOption.text.trim();
                // Если текст выбранной опции есть в карте дней и он совпадает с текущим днем
                if (dayMap.hasOwnProperty(selectedText) && dayMap[selectedText] === todayIndex) {
                    cell.classList.add('is-due-today');
                }
            }
        });
    }

    const saveHeights = function() {
        const heights = {
            deals: dealListContainer.offsetHeight,
            products: productListContainer.offsetHeight,
            details: detailsContainer.offsetHeight,
        };
        BX.ajax.runComponentAction('company:deal.dashboard2', 'saveHeights', { mode: 'class', data: { heights: heights } });
    };

    dashboard.querySelectorAll('.dashboard-resizer').forEach(resizer => {
        resizer.addEventListener('mousedown', function(e) {
            e.preventDefault();
            const elementToResize = document.getElementById(resizer.dataset.resizes);
            if (!elementToResize) return;
            const startY = e.clientY;
            const startHeight = elementToResize.offsetHeight;
            const doDrag = function(e) {
                const newHeight = startHeight + e.clientY - startY;
                if (newHeight > 50) elementToResize.style.height = newHeight + 'px';
            };
            const stopDrag = function() {
                document.removeEventListener('mousemove', doDrag);
                document.removeEventListener('mouseup', stopDrag);
                clearTimeout(saveHeightsTimeout);
                saveHeightsTimeout = setTimeout(saveHeights, 500);
            };
            document.addEventListener('mousemove', doDrag);
            document.addEventListener('mouseup', stopDrag);
        });
    });

    function showLoader(container) {
        if (container) container.innerHTML = '<div class="ui-spinner-wrapper"><div class="ui-spinner ui-spinner-lg"></div></div>';
    }
    
    function resetActiveItems(container) {
        if(container) container.querySelectorAll('.active').forEach(item => item.classList.remove('active'));
    }

    function showMoveMenu(anchorElement) {
        if (!activeDealItem) { alert('Пожалуйста, выберите заказ из списка.'); return; }
        const targetStagesIds = ['FINAL_INVOICE', '20', '31', '21', '28', '25'];
        const stageInfo = window.DEAL_DASHBOARD_STAGE_INFO || {};
        const menuItems = [];
        targetStagesIds.forEach(function(targetStageId) {
            const info = stageInfo[targetStageId];
            if (!info) return;
            const name = info.NAME;
            const color = info.COLOR || '#a8b4be';
            const hexToRgb = (hex) => {
                const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
                return result ? { r: parseInt(result[1], 16), g: parseInt(result[2], 16), b: parseInt(result[3], 16) } : null;
            };
            const rgb = hexToRgb(color);
            const brightness = rgb ? (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000 : 0;
            const textColor = brightness > 128 ? '#333' : '#fff';
            menuItems.push({
                html: `<div class="dashboard-move-menu-item" style="background-color: ${color}; color: ${textColor};">${name}</div>`,
                onclick: function(event, item) {
                    const dealId = activeDealItem.dataset.dealId;
                    item.getMenuWindow().close();
                    const row = activeDealItem;
                    row.style.opacity = '0.5';
                    BX.ajax.runComponentAction('company:deal.dashboard2', 'moveDeal', { mode: 'class', data: { dealId: dealId, targetStageId: targetStageId }
                    }).then(() => {
                        if (activeStageItem) activeStageItem.click();
                        else searchInput.value = '';
                    }).catch(response => {
                        row.style.opacity = '1';
                        alert(response.errors[0].message);
                    });
                }
            });
        });
        if (menuItems.length > 0) {
            const existingMenu = BX.PopupMenu.getMenuById('deal-move-menu');
            if (existingMenu) existingMenu.destroy();
            BX.PopupMenu.show('deal-move-menu', anchorElement, menuItems, { autoHide: true, closeByEsc: true, angle: true, className: 'dashboard-move-popup' });
        }
    }

    function renderDealActions(stageId) {
        const movableStages = ['20', '31', '28', '21_27', '25'];
        let actionsHtml = '';
        if (movableStages.includes(stageId)) {
            actionsHtml = `<button class="ui-btn ui-btn-sm ui-btn-light-border dashboard-actions-button" data-action="show-move-menu" title="Переместить сделку"><span class="dashboard-actions-icon"></span></button>`;
        }
        if (dealActionsContainer) dealActionsContainer.innerHTML = actionsHtml;
    }

    function applySortIndicator(containerId, sortBy, sortOrder) {
        const container = document.getElementById(containerId);
        if (!container) return;
        container.querySelectorAll('th[data-sort-key]').forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
            if (th.dataset.sortKey === sortBy) {
                th.classList.add(sortOrder === 'ASC' ? 'sort-asc' : 'sort-desc');
            }
        });
    }


    function loadStage(stageId, callback) {
        const stageItem = dashboard.querySelector(`.dashboard-stage-item[data-stage-id="${stageId}"]`);
        if (!stageItem) { if (typeof callback === 'function') callback(false); return; }
        
        if (downloadCsvBtn) downloadCsvBtn.style.display = 'none';
        
        if (activeStageItem) activeStageItem.classList.remove('active');
        activeStageItem = stageItem;
        activeStageItem.classList.add('active');
        renderDealActions(stageId);
        showLoader(dealListContainer);
        showLoader(productListContainer);
        if (detailsContainer) detailsContainer.innerHTML = '<div class="dashboard-placeholder">Выберите заказ или товар выше</div>';

        const stageSort = sortOptions[stageId] || {};
        const dealsSort = stageSort.deals || {};
        
        const requestData = { 
            stageId: stageId,
            tableKey: 'deals',
            sortBy: dealsSort.sortBy,
            sortOrder: dealsSort.sortOrder
        };

        if (warehouseFilterSelect) {
            currentFilter.warehouseId = warehouseFilterSelect.value;
        }

        if (currentFilter.type === 'product') {
            requestData.productSearchQuery = currentFilter.value;
        }
        if (currentFilter.warehouseId) {
            requestData.warehouseFilterId = currentFilter.warehouseId;
        }
        
        BX.ajax.runComponentAction('company:deal.dashboard2', 'getStageData', {
            mode: 'class',
            data: requestData
        }).then(function(response) {
            dealListContainer.innerHTML = response.data.dealsHtml;
            productListContainer.innerHTML = response.data.productsHtml;
            const productsSort = stageSort.products || {};
            applySortIndicator('deal-list-container', dealsSort.sortBy, dealsSort.sortOrder);
            applySortIndicator('product-list-container', productsSort.sortBy, productsSort.sortOrder);
            
            if (downloadCsvBtn) downloadCsvBtn.style.display = 'inline-block';

            // Выделяем "Все товары" по умолчанию
            const allProductsRow = productListContainer.querySelector('tr[data-product-id="all"]');
            if (allProductsRow) {
                allProductsRow.classList.add('active');
                activeProductItem = allProductsRow;
            }
            
            highlightDueTodayCells(); // <--- ВЫЗОВ ФУНКЦИИ ПОДСВЕТКИ

            if (typeof callback === 'function') callback(true);
        }).catch(function() {
            if (downloadCsvBtn) downloadCsvBtn.style.display = 'none';
            if (typeof callback === 'function') callback(false);
        });
    }
    
    function updateStageCounts(counts) {
        dashboard.querySelectorAll('.dashboard-stage-item').forEach(item => {
            const stageId = item.dataset.stageId;
            const counter = item.querySelector('.stage-counter');
            if (counter) {
                counter.textContent = counts[stageId] || 0;
            }
        });
    }

    function refreshStageCounts() {
        const searchQuery = productSearchInput.value.trim();
        const warehouseId = warehouseFilterSelect ? warehouseFilterSelect.value : null;

        return BX.ajax.runComponentAction('company:deal.dashboard2', 'getStageCounts', {
            mode: 'class',
            data: {
                warehouseFilterId: warehouseId,
                productSearchQuery: searchQuery.length >= 3 ? searchQuery : null
            }
        }).then(function(response) {
            updateStageCounts(response.data);
        });
    }

    if (warehouseFilterSelect) {
        warehouseFilterSelect.addEventListener('change', function() {
            currentFilter.warehouseId = this.value;
            refreshStageCounts();
            if (activeStageItem) {
                loadStage(activeStageItem.dataset.stageId);
            }
        });
    }

    dashboard.addEventListener('change', function(event) {
        const statusSelect = event.target.closest('.assembly-status-select, .shipping-warehouse-select');
        if (statusSelect) {
            const dealId = statusSelect.dataset.dealId;
            const value = statusSelect.value;
            let action = '';
            let data = {};

            if (statusSelect.classList.contains('assembly-status-select')) {
                action = 'updateAssemblyStatus';
                data = { dealId: dealId, statusId: value };
                // Обновляем цвет сразу после изменения
                highlightDueTodayCells(); 
            } else if (statusSelect.classList.contains('shipping-warehouse-select')) {
                action = 'updateShippingWarehouse';
                data = { dealId: dealId, warehouseId: value };
            }

            if (action) {
                statusSelect.classList.add('is-saving');
                statusSelect.classList.remove('is-success', 'is-error');
                BX.ajax.runComponentAction('company:deal.dashboard2', action, { 
                    mode: 'class', 
                    data: data 
                }).then(() => {
                    statusSelect.classList.remove('is-saving');
                    statusSelect.classList.add('is-success');
                    setTimeout(() => statusSelect.classList.remove('is-success'), 2000);
                }).catch(() => {
                    statusSelect.classList.remove('is-saving');
                    statusSelect.classList.add('is-error');
                    setTimeout(() => statusSelect.classList.remove('is-error'), 2000);
                });
            }
        }
    });

    dashboard.addEventListener('dblclick', function(event) {
        const th = event.target.closest('th[data-sort-key]');
        if (!th) return;

        const container = th.closest('.dashboard-list-container');
        if (!container) return;

        const tableKey = container.dataset.tableKey;
        const stageId = activeStageItem ? activeStageItem.dataset.stageId : null;
        if (!stageId) return;

        const sortBy = th.dataset.sortKey;
        if (!sortOptions[stageId]) sortOptions[stageId] = {};
        if (!sortOptions[stageId][tableKey]) sortOptions[stageId][tableKey] = {};
        
        let sortOrder = 'ASC';
        if (sortOptions[stageId][tableKey].sortBy === sortBy) {
            sortOrder = sortOptions[stageId][tableKey].sortOrder === 'ASC' ? 'DESC' : 'ASC';
        }

        sortOptions[stageId][tableKey] = { sortBy, sortOrder };

        BX.ajax.runComponentAction('company:deal.dashboard2', 'saveSortOptions', {
            mode: 'class', data: { sortOptions: sortOptions }
        });

        showLoader(container);
        
        if (tableKey === 'details') {
            const productId = activeProductItem ? activeProductItem.dataset.productId : null;
            if (productId) {
                 BX.ajax.runComponentAction('company:deal.dashboard2', 'getDealsByProduct', {
                     mode: 'class', data: { productId, stageId, sortBy, sortOrder }
                 }).then(response => {
                     container.innerHTML = response.data.html;
                     applySortIndicator(container.id, sortBy, sortOrder);
                     highlightDueTodayCells(); // <--- ВЫЗОВ ФУНКЦИИ ПОДСВЕТКИ
                 });
            }
        } else {
            const requestData = {
                stageId: stageId,
                tableKey: tableKey,
                sortBy: sortBy,
                sortOrder: sortOrder
            };
            if (currentFilter.type === 'product') {
                requestData.productSearchQuery = currentFilter.value;
            }
            if (currentFilter.warehouseId) {
                requestData.warehouseFilterId = currentFilter.warehouseId;
            }
            BX.ajax.runComponentAction('company:deal.dashboard2', 'getStageData', {
                mode: 'class',
                data: requestData
            }).then(response => {
                dealListContainer.innerHTML = response.data.dealsHtml;
                productListContainer.innerHTML = response.data.productsHtml;
                applySortIndicator('deal-list-container', (sortOptions[stageId].deals || {}).sortBy, (sortOptions[stageId].deals || {}).sortOrder);
                applySortIndicator('product-list-container', (sortOptions[stageId].products || {}).sortBy, (sortOptions[stageId].products || {}).sortOrder);
                highlightDueTodayCells(); // <--- ВЫЗОВ ФУНКЦИИ ПОДСВЕТКИ
            });
        }
    });

    function loadFilteredDeals(productId, setId) {
        if (!activeStageItem) return;
        const stageId = activeStageItem.dataset.stageId;

        showLoader(dealListContainer);
        activeDealItem = null;
        resetActiveItems(detailsContainer);
        if (detailsContainer) detailsContainer.innerHTML = '<div class="dashboard-placeholder">Выберите заказ или товар выше</div>';


        const dealsSort = (sortOptions[stageId] && sortOptions[stageId].deals) ? sortOptions[stageId].deals : {};
        
        const requestData = {
            stageId: stageId,
            tableKey: 'deals', // We only really need to update the deals list
            sortBy: dealsSort.sortBy,
            sortOrder: dealsSort.sortOrder,
            warehouseFilterId: currentFilter.warehouseId,
            productSearchQuery: currentFilter.type === 'product' ? currentFilter.value : null
        };

        if (productId && productId !== 'all') {
            requestData.filterByProductId = productId;
        } else if (setId) {
            requestData.filterBySetId = setId;
        }
        // If productId is 'all', no specific product filter is added

        BX.ajax.runComponentAction('company:deal.dashboard2', 'getStageData', {
            mode: 'class',
            data: requestData
        }).then(function(response) {
            // Only update the deals list. The products list stays the same.
            dealListContainer.innerHTML = response.data.dealsHtml;
            applySortIndicator('deal-list-container', dealsSort.sortBy, dealsSort.sortOrder);
            highlightDueTodayCells(); // <--- ВЫЗОВ ФУНКЦИИ ПОДСВЕТКИ
        });
    }

    dashboard.addEventListener('click', function(event) {
        if (event.detail > 1) return;

        const chatIcon = event.target.closest('.item-chat-icon[data-chat-id]');
        if (chatIcon) {
            event.stopPropagation();
            const chatId = chatIcon.dataset.chatId;
            const isMember = chatIcon.dataset.isMember === 'true';

            const openChat = () => {
                if (window.BXIM) {
                    BXIM.openHistory('chat' + chatId);
                }
            };

            if (isMember) {
                openChat();
            } else {
                chatIcon.classList.add('is-loading');
                BX.ajax.runComponentAction('company:deal.dashboard2', 'joinChat', {
                    mode: 'class',
                    data: { chatId: chatId }
                }).then(function() {
                    chatIcon.classList.remove('is-loading');
                    chatIcon.dataset.isMember = 'true';
                    openChat();
                }).catch(function(response) {
                    chatIcon.classList.remove('is-loading');
                    alert(response.errors[0].message);
                });
            }
            return;
        }

        const addChatIcon = event.target.closest('.item-chat-icon[data-deal-id-for-chat]');
        if (addChatIcon) {
            event.stopPropagation();
            const dealId = addChatIcon.dataset.dealIdForChat;
            
            addChatIcon.classList.add('is-loading');
            
            BX.ajax.runComponentAction('company:deal.dashboard2', 'createDealChat', {
                mode: 'class',
                data: { dealId: dealId }
            }).then(function(response) {
                const chatId = response.data.chatId;
                addChatIcon.classList.remove('is-loading', 'is-add');
                addChatIcon.removeAttribute('data-deal-id-for-chat');
                addChatIcon.dataset.chatId = chatId;
                addChatIcon.dataset.isMember = 'true';
                addChatIcon.title = 'Открыть чат';
                
                if (window.BXIM) {
                    BXIM.openHistory('chat' + chatId);
                }
            }).catch(function(response) {
                addChatIcon.classList.remove('is-loading');
                alert(response.errors[0].message);
            });
            return;
        }


        const closingDocLink = event.target.closest('.item-closing-doc[data-slider-url]');
        if (closingDocLink) { event.preventDefault(); event.stopPropagation(); if (BX.SidePanel) BX.SidePanel.Instance.open(closingDocLink.dataset.sliderUrl); return; }

        const moveButton = event.target.closest('[data-action="show-move-menu"]');
        if (moveButton) { showMoveMenu(moveButton); return; }

        const stageItem = event.target.closest('.dashboard-stage-item');
        if (stageItem) { loadStage(stageItem.dataset.stageId); return; }
        
        const dealItem = event.target.closest('.dashboard-list-item[data-deal-id]');
        if (dealItem) {
            const parentContainer = dealItem.closest('.dashboard-list-container');
            if (parentContainer) {
                if (dealItem.classList.contains('active') && event.target.classList.contains('item-name')) {
                    if (BX.SidePanel && dealItem.dataset.url) BX.SidePanel.Instance.open(dealItem.dataset.url);
                    return;
                }
                if (parentContainer.id === 'deal-list-container') {
                    resetActiveItems(parentContainer);
                    dealItem.classList.add('active');
                    activeDealItem = dealItem;
                    // activeProductItem remains the same to keep the filter context
                    showLoader(detailsContainer);
                    const stageId = activeStageItem ? activeStageItem.dataset.stageId : null;
                    
                    const requestData = { 
                        dealId: dealItem.dataset.dealId, 
                        stageId: stageId 
                    };
                    if (currentFilter.type === 'product') {
                        requestData.productSearchQuery = currentFilter.value;
                    }
                    
                    BX.ajax.runComponentAction('company:deal.dashboard2', 'getDealProducts', { 
                        mode: 'class', 
                        data: requestData
                    })
                    .then(response => { if (detailsContainer) detailsContainer.innerHTML = response.data.html; });
                }
            }
            return;
        }

        const setLink = event.target.closest('.item-set-link[data-slider-url]');
        if (setLink) {
            event.preventDefault();
            event.stopPropagation();
            if (BX.SidePanel) {
                BX.SidePanel.Instance.open(setLink.dataset.sliderUrl);
            }
            return;
        }


        const productItem = event.target.closest('.dashboard-list-item[data-product-id], .dashboard-list-item[data-set-id]');
        if (productItem) {
            const parentContainer = productItem.closest('.dashboard-list-container');
            if (parentContainer && parentContainer.id === 'product-list-container') {
                if (productItem.classList.contains('active') && event.target.classList.contains('item-name')) {
                    if (BX.SidePanel && productItem.dataset.url) BX.SidePanel.Instance.open(productItem.dataset.url);
                    return;
                }
                
                if (activeProductItem) activeProductItem.classList.remove('active');
                productItem.classList.add('active');
                activeProductItem = productItem;
                
                const productId = productItem.dataset.productId;
                const setId = productItem.dataset.setId;

                loadFilteredDeals(productId, setId);
                return;
            } 
            else if (parentContainer && parentContainer.id === 'details-container') {
                if (productItem.classList.contains('active') && event.target.classList.contains('item-name')) {
                    if (BX.SidePanel && productItem.dataset.url) BX.SidePanel.Instance.open(productItem.dataset.url);
                    return;
                }
                resetActiveItems(detailsContainer);
                productItem.classList.add('active');
            }
            return;
        }
    });

    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const dealId = parseInt(this.value.trim(), 10);
                if (!dealId) return;

                if (downloadCsvBtn) downloadCsvBtn.style.display = 'none';

                const inputElement = this;
                inputElement.disabled = true;
                BX.ajax.runComponentAction('company:deal.dashboard2', 'findDeal', { mode: 'class', data: { dealId: dealId }
                }).then(
                    function(response) {
                        const stageId = response.data.stageId;
                        loadStage(stageId, function(isSuccess) {
                            if (isSuccess) {
                                setTimeout(function() {
                                    const dealElement = dealListContainer.querySelector(`[data-deal-id="${dealId}"]`);
                                    if (dealElement) {
                                        dealElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        dealElement.click();
                                    } else {
                                        alert(`Сделка с ID ${dealId} найдена, но не отображается в списке для этой стадии.`);
                                    }
                                }, 150);
                            } else {
                                alert('Не удалось загрузить стадию.');
                            }
                            inputElement.disabled = false;
                        });
                    },
                    function(response) {
                        alert(response.errors[0].message);
                        inputElement.disabled = false;
                    }
                );
            }
        });
    }

    if (productSearchInput) {
        productSearchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const searchQuery = this.value.trim();
                if (searchQuery.length < 3) {
                    if (searchQuery.length === 0) {
                        productSearchReset.click();
                    } else {
                        alert('Введите минимум 3 символа для поиска.');
                    }
                    return;
                }
                
                this.disabled = true;
                currentFilter.type = 'product';
                currentFilter.value = searchQuery;
                productSearchReset.style.display = 'block';

                refreshStageCounts().then(() => {
                    this.disabled = false;
                    if (activeStageItem) {
                        loadStage(activeStageItem.dataset.stageId);
                    }
                });
            }
        });
    }

    if (productSearchReset) {
        productSearchReset.addEventListener('click', function() {
            productSearchInput.value = '';
            productSearchInput.disabled = false;
            this.style.display = 'none';
            currentFilter.type = 'none';
            currentFilter.value = null;
            
            refreshStageCounts();

            if (activeStageItem) {
                loadStage(activeStageItem.dataset.stageId);
            }
        });
    }

    function convertToCSV(data) {
        if (!data || data.length === 0) {
            return '';
        }
        const headers = '"Название","Требуется"';
        const rows = data.map(item => {
            const productName = (item.PRODUCT_NAME || '').toString().replace(/"/g, '""');
            const totalQuantity = item.TOTAL_QUANTITY || 0;
            return `"${productName}","${totalQuantity}"`;
        });
        return [headers, ...rows].join('\r\n');
    }

    function downloadCSV(csvContent, fileName) {
        const blob = new Blob([new Uint8Array([0xEF, 0xBB, 0xBF]), csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement("a");
        
        const url = URL.createObjectURL(blob);
        link.setAttribute("href", url);
        link.setAttribute("download", fileName);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    if (downloadCsvBtn) {
        downloadCsvBtn.addEventListener('click', function() {
            if (!activeStageItem) {
                alert('Сначала выберите стадию.');
                return;
            }
            const stageId = activeStageItem.dataset.stageId;
            const originalText = this.innerHTML;
            
            this.innerHTML = 'Загрузка...';
            this.disabled = true;

            BX.ajax.runComponentAction('company:deal.dashboard2', 'getProductsForCsv', {
                mode: 'class',
                data: { stageId: stageId }
            }).then(response => {
                this.innerHTML = originalText;
                this.disabled = false;
                const products = response.data;
                if (!products || products.length === 0) {
                    alert('Нет товаров для выгрузки в этой стадии.');
                    return;
                }
                const csvContent = convertToCSV(products);
                const fileName = `products-stage-${stageId}.csv`;
                downloadCSV(csvContent, fileName);
            }).catch(response => {
                this.innerHTML = originalText;
                this.disabled = false;
                const errorMessage = response && response.errors && response.errors[0] ? response.errors[0].message : 'Неизвестная ошибка';
                alert('Ошибка при выгрузке данных: ' + errorMessage);
            });
        });
    }
});
