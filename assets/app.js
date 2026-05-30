// === API Helper ===
async function api(path, options = {}) {
    const res = await fetch('api/' + path, {
        headers: { 'Content-Type': 'application/json' },
        ...options
    });
    const text = await res.text();
    if (!text) throw new Error('服务器返回空响应 (HTTP ' + res.status + ')');
    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        throw new Error('服务器返回非JSON: ' + text.substring(0, 200) + ' (HTTP ' + res.status + ')');
    }
    if (!res.ok) throw new Error(data.error || '请求失败 (HTTP ' + res.status + ')');
    return data;
}

// === Toast ===
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 2500);
}

// === Modal ===
function openModal(id) { document.getElementById(id).hidden = false; }
function closeModal(id) { document.getElementById(id).hidden = true; document.getElementById(id).querySelector('form')?.reset(); }
document.querySelectorAll('.modal-close, .modal-cancel').forEach(btn => {
    btn.addEventListener('click', () => {
        btn.closest('.modal').hidden = true;
        btn.closest('.modal').querySelector('form')?.reset();
    });
});

// === Confirm Modal ===
let confirmCallback = null;
function showConfirm(message, onConfirm) {
    document.getElementById('confirm-msg').textContent = message;
    confirmCallback = onConfirm;
    openModal('modal-confirm');
}
document.getElementById('confirm-cancel').addEventListener('click', () => {
    closeModal('modal-confirm');
    confirmCallback = null;
});
document.getElementById('confirm-ok').addEventListener('click', () => {
    closeModal('modal-confirm');
    if (confirmCallback) { confirmCallback(); confirmCallback = null; }
});

// === Tab Switching ===
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        loadTab(btn.dataset.tab);
    });
});

function loadTab(tab) {
    switch (tab) {
        case 'dashboard': loadDashboard(); break;
        case 'add-item': loadAddItemTab(); break;
        case 'inventory': loadInventoryTab(); break;
        case 'transfer': loadTransferTab(); break;
        case 'fridges': loadFridges(); break;
        case 'data': loadDataTab(); break;
    }
}

// ========== Tab 1: 冰箱管理 ==========
async function loadFridges() {
    const list = document.getElementById('fridge-list');
    list.innerHTML = '<div class="loading">加载中...</div>';
    try {
        const fridges = await api('fridge.php');
        if (!fridges.length) {
            list.innerHTML = '<div class="empty-state">还没有冰箱，点击上方按钮添加</div>';
            return;
        }
        list.innerHTML = fridges.map(f => `
            <div class="card">
                <div class="card-header">
                    <span class="card-title">${esc(f.name)}</span>
                    <div class="card-actions">
                        <button onclick="editFridge(${f.id})" title="编辑">✎</button>
                        <button class="danger" onclick="deleteFridge(${f.id})" title="删除">✕</button>
                    </div>
                </div>
                <div class="card-meta">
                    <span>📦 ${f.item_count} 件物品</span>
                    ${f.location ? '<span>📍 ' + esc(f.location) + '</span>' : ''}
                </div>
                ${f.description ? '<div class="card-subtitle" style="margin-top:.35rem;">' + esc(f.description) + '</div>' : ''}
            </div>
        `).join('');
    } catch (e) {
        list.innerHTML = '<div class="empty-state">加载失败: ' + esc(e.message) + '</div>';
    }
}

document.getElementById('btn-add-fridge').addEventListener('click', () => {
    document.getElementById('modal-fridge-title').textContent = '添加冰箱';
    document.getElementById('fridge-id').value = '';
    openModal('modal-fridge');
});

document.getElementById('form-fridge').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('fridge-id').value;
    const body = {
        name: document.getElementById('fridge-name').value.trim(),
        location: document.getElementById('fridge-location').value.trim(),
        description: document.getElementById('fridge-desc').value.trim(),
    };
    try {
        if (id) {
            body.id = parseInt(id);
            await api('fridge.php', { method: 'PUT', body: JSON.stringify(body) });
            toast('冰箱已更新');
        } else {
            await api('fridge.php', { method: 'POST', body: JSON.stringify(body) });
            toast('冰箱创建成功');
        }
        closeModal('modal-fridge');
        loadFridges();
    } catch (err) { toast(err.message, 'error'); }
});

async function editFridge(id) {
    const f = await api('fridge.php?id=' + id);
    document.getElementById('modal-fridge-title').textContent = '编辑冰箱';
    document.getElementById('fridge-id').value = f.id;
    document.getElementById('fridge-name').value = f.name;
    document.getElementById('fridge-location').value = f.location || '';
    document.getElementById('fridge-desc').value = f.description || '';
    openModal('modal-fridge');
}

async function deleteFridge(id) {
    if (!confirm('确定要删除这台冰箱吗？冰箱内的物品也会被一并删除。')) return;
    try {
        await api('fridge.php?id=' + id, { method: 'DELETE' });
        toast('冰箱已删除');
        loadFridges();
    } catch (err) { toast(err.message, 'error'); }
}

// ========== Tab 2: 库存浏览 ==========
let invCurrentPage = 1;

async function loadInventoryTab() {
    const fridges = await api('fridge.php');
    const select = document.getElementById('inv-fridge-select');
    select.innerHTML = '<option value="">-- 全部冰箱 --</option>' + fridges.map(f => `<option value="${f.id}">${esc(f.name)} (${f.item_count}件)</option>`).join('');

    const cats = await api('categories.php');
    document.getElementById('inv-category-select').innerHTML = '<option value="">-- 全部分类 --</option>' + cats.map(c => `<option value="${c.id}">${c.icon} ${esc(c.name)}</option>`).join('');

    loadItems(1);
}

async function loadItems(page) {
    invCurrentPage = page || invCurrentPage;
    const fridge_id = document.getElementById('inv-fridge-select').value;
    const category_id = document.getElementById('inv-category-select').value;
    const sort = document.getElementById('inv-sort-select').value;
    const storage_type = document.getElementById('inv-storage-select').value;
    const search = document.getElementById('inv-search').value.trim();

    const list = document.getElementById('item-list');
    const pagination = document.getElementById('pagination');
    list.innerHTML = '<div class="loading">加载中...</div>';
    pagination.innerHTML = '';
    try {
        const params = new URLSearchParams({ page: invCurrentPage, per_page: 30, sort: sort });
        if (fridge_id) params.set('fridge_id', fridge_id);
        if (category_id) params.set('category_id', category_id);
        if (search) params.set('search', search);
        if (storage_type) params.set('storage_type', storage_type);

        const data = await api('items.php?' + params.toString());
        const items = data.items;
        const total = data.total;
        const totalPages = Math.ceil(total / data.per_page);

        document.getElementById('inv-summary').textContent = `共 ${total} 件物品，第 ${invCurrentPage}/${totalPages} 页`;

        if (!items.length) {
            list.innerHTML = '<div class="empty-state">暂无物品</div>';
            return;
        }
        list.innerHTML = items.map(item => {
            let cls = 'item-card';
            let badge = '';
            if (item.is_expired) { cls += ' expired'; badge = '<span class="badge badge-danger">已过期</span>'; }
            else if (item.is_expiring) { cls += ' expiring'; badge = '<span class="badge badge-warning">临期</span>'; }

            return `<div class="card ${cls}">
                <div class="card-header">
                    <span class="card-title">${item.category_icon} ${esc(item.name)} ${badge}</span>
                    <div class="card-actions">
                        <button onclick="consumeItem(${item.id}, event)" title="取用（-1）" class="btn-consume">−1</button>
                        <button onclick="editItem(${item.id})" title="编辑">✎</button>
                        <button class="danger" onclick="deleteItem(${item.id})" title="删除">✕</button>
                    </div>
                </div>
                <div class="card-meta">
                    <span>🧊 ${esc(item.fridge_name)}</span>
                    <span>📂 ${esc(item.category_name)}</span>
                    <span>📦 ${item.quantity} ${item.unit}</span>
                    <span>⏱ ${item.days_stored} 天</span>
                </div>
                ${item.storage_type === 'frozen' ? '<div class="card-meta">🧊 冷冻</div>' : (item.storage_type === 'cold' ? '<div class="card-meta">❄️ 冷藏</div>' : '')}
                ${item.production_date ? '<div class="card-meta">🏭 生产: ' + item.production_date + '</div>' : ''}
                ${item.shelf_life_value ? '<div class="card-meta">⏳ 保质: ' + item.shelf_life_value + ' ' + (item.shelf_life_unit === 'year' ? '年' : item.shelf_life_unit === 'month' ? '月' : '天') + '</div>' : ''}
                ${item.expire_date ? '<div class="card-meta">⏰ 过期: ' + item.expire_date + '</div>' : ''}
                ${item.notes ? '<div class="card-subtitle" style="margin-top:.25rem;">' + esc(item.notes) + '</div>' : ''}
            </div>`;
        }).join('');

        // Pagination controls
        if (totalPages > 1) {
            let html = '<div class="pagination-inner">';
            html += `<button class="btn btn-sm btn-page" onclick="loadItems(1)" ${invCurrentPage === 1 ? 'disabled' : ''}>« 首页</button>`;
            html += `<button class="btn btn-sm btn-page" onclick="loadItems(${invCurrentPage - 1})" ${invCurrentPage === 1 ? 'disabled' : ''}>‹ 上一页</button>`;
            html += `<span class="page-info">${invCurrentPage} / ${totalPages}</span>`;
            html += `<button class="btn btn-sm btn-page" onclick="loadItems(${invCurrentPage + 1})" ${invCurrentPage === totalPages ? 'disabled' : ''}>下一页 ›</button>`;
            html += `<button class="btn btn-sm btn-page" onclick="loadItems(${totalPages})" ${invCurrentPage === totalPages ? 'disabled' : ''}>末页 »</button>`;
            html += '</div>';
            pagination.innerHTML = html;
        }
    } catch (e) {
        list.innerHTML = '<div class="empty-state">加载失败: ' + esc(e.message) + '</div>';
    }
}

document.getElementById('inv-fridge-select').addEventListener('change', () => loadItems(1));
document.getElementById('inv-category-select').addEventListener('change', () => loadItems(1));
document.getElementById('inv-sort-select').addEventListener('change', () => loadItems(1));
document.getElementById('inv-storage-select').addEventListener('change', () => loadItems(1));
document.getElementById('inv-search').addEventListener('input', debounce(() => loadItems(1), 300));

// ========== Tab 2: 添加物品 ==========
async function loadAddItemTab() {
    const fridges = await api('fridge.php');
    document.getElementById('add-fridge').innerHTML = fridges.map(f => `<option value="${f.id}">${esc(f.name)}</option>`).join('');

    const cats = await api('categories.php');
    document.getElementById('add-category').innerHTML = cats.map(c => `<option value="${c.id}">${c.icon} ${esc(c.name)}</option>`).join('');

    document.getElementById('add-added').value = new Date().toISOString().slice(0, 10);
}

// 冷藏/冷冻切换 + 过期提示
const storageHints = {
    cold: '未填生产日期和保质期时，默认 添加日期+5天 过期',
    frozen: '未填生产日期和保质期时，默认 添加日期+90天 过期'
};
document.querySelectorAll('.storage-option').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.storage-option').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const hint = document.getElementById('storage-hint');
        if (hint) hint.textContent = storageHints[btn.dataset.type] || '';
    });
});

document.getElementById('form-add-item').addEventListener('submit', async (e) => {
    e.preventDefault();
    const shelfValue = document.getElementById('add-shelf-life-value').value;
    const storageType = document.querySelector('.storage-option.active')?.dataset?.type || 'cold';
    const body = {
        name: document.getElementById('add-name').value.trim(),
        category_id: parseInt(document.getElementById('add-category').value),
        fridge_id: parseInt(document.getElementById('add-fridge').value),
        quantity: parseFloat(document.getElementById('add-quantity').value) || 1,
        unit: document.getElementById('add-unit').value,
        production_date: document.getElementById('add-production-date').value || null,
        shelf_life_value: shelfValue ? parseInt(shelfValue) : null,
        shelf_life_unit: shelfValue ? document.getElementById('add-shelf-life-unit').value : null,
        storage_type: storageType,
        added_date: document.getElementById('add-added').value || null,
        notes: document.getElementById('add-notes').value.trim(),
    };
    try {
        await api('items.php', { method: 'POST', body: JSON.stringify(body) });
        toast('物品添加成功');
        document.getElementById('form-add-item').reset();
        document.getElementById('add-added').value = new Date().toISOString().slice(0, 10);
        document.querySelectorAll('.storage-option').forEach(b => b.classList.remove('active'));
        document.querySelector('.storage-option[data-type="cold"]').classList.add('active');
        document.getElementById('storage-hint').textContent = storageHints.cold;
    } catch (err) { toast(err.message, 'error'); }
});

async function populateItemFormSelects() {
    const cats = await api('categories.php');
    document.getElementById('item-category').innerHTML = cats.map(c => `<option value="${c.id}">${c.icon} ${esc(c.name)}</option>`).join('');
    const fridges = await api('fridge.php');
    document.getElementById('item-fridge').innerHTML = fridges.map(f => `<option value="${f.id}">${esc(f.name)}</option>`).join('');
}

document.getElementById('form-item').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('item-id').value;
    const shelfValue = document.getElementById('item-shelf-life-value').value;
    const body = {
        name: document.getElementById('item-name').value.trim(),
        category_id: parseInt(document.getElementById('item-category').value),
        fridge_id: parseInt(document.getElementById('item-fridge').value),
        quantity: parseFloat(document.getElementById('item-quantity').value) || 1,
        unit: document.getElementById('item-unit').value,
        production_date: document.getElementById('item-production-date').value || null,
        shelf_life_value: shelfValue ? parseInt(shelfValue) : null,
        shelf_life_unit: shelfValue ? document.getElementById('item-shelf-life-unit').value : null,
        storage_type: document.getElementById('item-storage-type').value || null,
        added_date: document.getElementById('item-added').value || null,
        notes: document.getElementById('item-notes').value.trim(),
    };
    try {
        if (id) {
            body.id = parseInt(id);
            await api('items.php', { method: 'PUT', body: JSON.stringify(body) });
            toast('物品已更新');
        } else {
            await api('items.php', { method: 'POST', body: JSON.stringify(body) });
            toast('物品添加成功');
        }
        closeModal('modal-item');
        loadItems();
    } catch (err) { toast(err.message, 'error'); }
});

async function editItem(id) {
    const items = await api('items.php?id=' + id);
    // items.php returns array when listing, but single param may not filter; use fridge_id approach
    // GET items.php with ?id= isn't supported, so fetch by search then filter
    // Instead, use POST-style retrieval via all items and find
    const all = await api('items.php?search=');
    const item = all.find(i => i.id == id);
    if (!item) { toast('物品不存在', 'error'); return; }

    document.getElementById('modal-item-title').textContent = '编辑物品';
    document.getElementById('item-id').value = item.id;
    document.getElementById('item-name').value = item.name;
    document.getElementById('item-quantity').value = item.quantity;
    document.getElementById('item-unit').value = item.unit;
    document.getElementById('item-production-date').value = item.production_date || '';
    document.getElementById('item-shelf-life-value').value = item.shelf_life_value || '';
    document.getElementById('item-shelf-life-unit').value = item.shelf_life_unit || 'day';
    document.getElementById('item-storage-type').value = item.storage_type || '';
    document.getElementById('item-added').value = item.added_date || '';
    document.getElementById('item-notes').value = item.notes || '';
    await populateItemFormSelects();
    document.getElementById('item-category').value = item.category_id;
    document.getElementById('item-fridge').value = item.fridge_id;
    openModal('modal-item');
}

async function deleteItem(id) {
    if (!confirm('确定要删除这个物品吗？')) return;
    try {
        await api('items.php?id=' + id, { method: 'DELETE' });
        toast('物品已删除');
        loadItems();
    } catch (err) { toast(err.message, 'error'); }
}

async function consumeItem(id, event) {
    if (event) event.stopPropagation();
    showConfirm('确定取用 1 个吗？数量减到 0 会自动删除。', async () => {
        try {
            const data = await api('consume.php', {
                method: 'POST',
                body: JSON.stringify({ id })
            });
            toast(data.message);
            loadItems();
        } catch (err) { toast(err.message, 'error'); }
    });
}

// ========== Tab 3: 物品转移 ==========
let _transferItems = [];

async function loadTransferTab() {
    const fridges = await api('fridge.php');
    const opts = fridges.map(f => `<option value="${f.id}">${esc(f.name)} (${f.item_count}件)</option>`).join('');
    document.getElementById('t-from-fridge').innerHTML = '<option value="">-- 选择来源冰箱 --</option>' + opts;
    document.getElementById('t-to-fridge').innerHTML = '<option value="">-- 选择目标冰箱 --</option>' + opts;
    // 分类筛选
    const cats = await api('categories.php');
    document.getElementById('t-category').innerHTML = '<option value="">-- 全部分类 --</option>' + cats.map(c => `<option value="${c.id}">${c.icon} ${esc(c.name)}</option>`).join('');
    loadTransferLogs();
}

function renderTransferItems() {
    const container = document.getElementById('transfer-items');
    const btn = document.getElementById('btn-transfer');
    const catId = document.getElementById('t-category').value;
    const search = document.getElementById('t-search').value.trim().toLowerCase();

    if (!_transferItems.length) {
        container.innerHTML = '';
        btn.style.display = 'none';
        return;
    }

    let filtered = _transferItems;
    if (catId) filtered = filtered.filter(i => i.category_id == catId);
    if (search) filtered = filtered.filter(i => i.name.toLowerCase().includes(search));

    if (!filtered.length) {
        container.innerHTML = '<div class="empty-state">无匹配物品</div>';
        btn.style.display = 'block';
        return;
    }

    container.innerHTML = filtered.map(item => {
        let badge = '';
        if (item.is_expired) badge = ' <span class="badge badge-danger">已过期</span>';
        else if (item.is_expiring) badge = ' <span class="badge badge-warning">临期</span>';
        return `
        <div class="card transfer-item" data-id="${item.id}" data-max="${item.quantity}" onclick="toggleTransferItem(this)">
            <div class="card-header">
                <span class="card-title">${item.category_icon} ${esc(item.name)}${badge}</span>
                <span class="text-muted">库存: ${item.quantity} ${item.unit}</span>
            </div>
            ${item.expire_date ? '<div class="card-meta" style="margin-top:.25rem;">⏰ 过期: ' + item.expire_date + (item.days_left !== null ? ' (' + (item.days_left < 0 ? '已过' + Math.abs(item.days_left) + '天' : '剩' + item.days_left + '天') + ')' : '') + '</div>' : ''}
            <div class="transfer-qty" style="display:none;">
                <label>转移数量: <input type="number" value="1" min="0.01" step="0.01" max="${item.quantity}" onclick="event.stopPropagation()"></label>
            </div>
        </div>
    `}).join('');
    btn.style.display = 'block';
}

document.getElementById('t-from-fridge').addEventListener('change', async () => {
    const fridge_id = document.getElementById('t-from-fridge').value;
    const container = document.getElementById('transfer-items');
    const btn = document.getElementById('btn-transfer');
    if (!fridge_id) { container.innerHTML = ''; btn.style.display = 'none'; _transferItems = []; return; }

    container.innerHTML = '<div class="loading">加载中...</div>';
    _transferItems = await api('items.php?fridge_id=' + fridge_id);
    renderTransferItems();
});

document.getElementById('t-category').addEventListener('change', renderTransferItems);
document.getElementById('t-search').addEventListener('input', debounce(renderTransferItems, 300));

function toggleTransferItem(el) {
    el.classList.toggle('selected');
    const qtyDiv = el.querySelector('.transfer-qty');
    qtyDiv.style.display = el.classList.contains('selected') ? 'block' : 'none';
}

document.getElementById('btn-transfer').addEventListener('click', async () => {
    const to_id = document.getElementById('t-to-fridge').value;
    const from_id = document.getElementById('t-from-fridge').value;
    if (!to_id) { toast('请选择目标冰箱', 'error'); return; }
    if (from_id === to_id) { toast('来源和目标不能相同', 'error'); return; }

    const selected = document.querySelectorAll('.transfer-item.selected');
    if (!selected.length) { toast('请选择要转移的物品', 'error'); return; }

    try {
        for (const el of selected) {
            const itemId = parseInt(el.dataset.id);
            const qtyInput = el.querySelector('input');
            const quantity = qtyInput ? parseFloat(qtyInput.value) : 1;
            await api('transfer.php', {
                method: 'POST',
                body: JSON.stringify({ item_id: itemId, from_fridge_id: parseInt(from_id), to_fridge_id: parseInt(to_id), quantity })
            });
        }
        toast(`已转移 ${selected.length} 种物品`);
        loadTransferTab();
    } catch (err) { toast(err.message, 'error'); }
});

async function loadTransferLogs() {
    const container = document.getElementById('transfer-logs');
    try {
        const logs = await api('transfer.php');
        if (!logs.length) {
            container.innerHTML = '<div class="log-item empty-state">暂无转移记录</div>';
            return;
        }
        container.innerHTML = logs.map(l => `
            <div class="log-item">
                <span>📦 ${esc(l.item_name)} ×${l.quantity}</span>
                <span>${esc(l.from_fridge_name)} → ${esc(l.to_fridge_name)}</span>
                <span class="log-time">${l.transfer_time}</span>
            </div>
        `).join('');
    } catch (e) {
        container.innerHTML = '<div class="log-item empty-state">加载失败</div>';
    }
}

// ========== Tab 4: 汇总看板 ==========
async function loadDashboard() {
    try {
        const data = await api('dashboard.php');

        // Summary cards
        document.getElementById('dashboard-summary').innerHTML = `
            <div class="summary-card">
                <div class="s-value">${data.summary.fridge_count}</div>
                <div class="s-label">冰箱总数</div>
            </div>
            <div class="summary-card">
                <div class="s-value">${data.summary.item_count}</div>
                <div class="s-label">物品总数</div>
            </div>
            <div class="summary-card ${data.summary.expiring_count > 0 ? 'warn' : ''}">
                <div class="s-value">${data.summary.expiring_count}</div>
                <div class="s-label">临期(3天内)</div>
            </div>
            <div class="summary-card ${data.summary.expired_count > 0 ? 'danger' : ''}">
                <div class="s-value">${data.summary.expired_count}</div>
                <div class="s-label">已过期</div>
            </div>
            <div class="summary-card ${data.summary.warning_count > 0 ? 'warn' : ''}">
                <div class="s-value">${data.summary.warning_count}</div>
                <div class="s-label">未标注预警</div>
            </div>
        `;

        // Fridge stats
        const maxQty = Math.max(...data.fridge_stats.map(f => parseInt(f.item_count) || 0), 1);
        document.getElementById('dash-fridge-stats').innerHTML = data.fridge_stats.length
            ? data.fridge_stats.map(f => `
                <div class="stat-row">
                    <span>${esc(f.name)}</span>
                    <div class="stat-bar"><div class="stat-bar-fill" style="width:${(f.item_count / maxQty) * 100}%"></div></div>
                    <span>${f.item_count} 件</span>
                </div>
            `).join('')
            : '<div class="text-muted">暂无冰箱</div>';

        // Expiring items
        document.getElementById('dash-expiring').innerHTML = data.expiring_items.length
            ? data.expiring_items.map(i => `
                <div class="dash-list-item">
                    <span>${i.category_icon}</span>
                    <span class="dl-name">${esc(i.name)}</span>
                    <span class="text-muted">${esc(i.fridge_name)}</span>
                    <span class="badge ${i.days_left < 0 ? 'badge-danger' : 'badge-warning'}">${i.days_left < 0 ? '已过期' : i.days_left + '天后'}</span>
                </div>
            `).join('')
            : '<div class="text-muted">暂无临期物品</div>';

        // Recent items
        document.getElementById('dash-recent').innerHTML = data.recent_items.length
            ? data.recent_items.map(i => `
                <div class="dash-list-item">
                    <span>${i.category_icon}</span>
                    <span class="dl-name">${esc(i.name)}</span>
                    <span class="text-muted">${i.quantity}${i.unit}</span>
                    <span class="text-muted">${esc(i.fridge_name)}</span>
                    <span class="text-muted" style="font-size:.8rem;">${i.added_date}</span>
                </div>
            `).join('')
            : '<div class="text-muted">最近7天无添加</div>';

        // Popular items
        document.getElementById('dash-popular').innerHTML = data.popular_items.length
            ? data.popular_items.map((i, idx) => `
                <div class="dash-list-item">
                    <span>${idx + 1}.</span>
                    <span>${i.category_icon}</span>
                    <span class="dl-name">${esc(i.name)}</span>
                    <span class="text-muted">总量 ${i.total_quantity} · ${i.fridge_count}台冰箱</span>
                </div>
            `).join('')
            : '<div class="text-muted">暂无数据</div>';

        // Warning items (未标注物品)
        document.getElementById('dash-warning').innerHTML = data.warning_items && data.warning_items.length
            ? data.warning_items.map(i => `
                <div class="dash-list-item">
                    <span>${i.category_icon}</span>
                    <span class="dl-name">${esc(i.name)}</span>
                    <span class="text-muted">${i.quantity}${i.unit}</span>
                    <span class="text-muted">${esc(i.fridge_name)}</span>
                    <span class="text-muted">${i.storage_type === 'frozen' ? '🧊冷冻' : i.storage_type === 'cold' ? '❄️冷藏' : '未指定'}</span>
                    <span class="badge badge-warning">${i.days_stored}天</span>
                </div>
            `).join('')
            : '<div class="text-muted">暂无未标注物品，👍</div>';
    } catch (e) {
        document.getElementById('dashboard-summary').innerHTML = '<div class="empty-state">加载失败: ' + esc(e.message) + '</div>';
    }
}

// ========== Helpers ==========
function esc(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function debounce(fn, delay) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

// ========== Tab 5: 数据导入导出 ==========
function loadDataTab() {
    // 无需预加载数据，按钮点击时处理
}

// 导出 Markdown
document.getElementById('btn-export-md').addEventListener('click', async () => {
    try {
        const res = await fetch('api/export_md.php');
        if (!res.ok) { const e = await res.json(); throw new Error(e.error || '导出失败'); }
        const blob = await res.blob();
        downloadBlob(blob, 'fridge-export-' + dateStamp() + '.md');
        toast('Markdown 导出成功');
    } catch (e) { toast(e.message, 'error'); }
});

// 导出 SQL
document.getElementById('btn-export-sql').addEventListener('click', async () => {
    try {
        const res = await fetch('api/export_sql.php');
        if (!res.ok) { const e = await res.json(); throw new Error(e.error || '导出失败'); }
        const blob = await res.blob();
        downloadBlob(blob, 'fridge-export-' + dateStamp() + '.sql');
        toast('SQL 导出成功');
    } catch (e) { toast(e.message, 'error'); }
});

// 导出 CSV
document.getElementById('btn-export-csv').addEventListener('click', async () => {
    try {
        const res = await fetch('api/export_csv.php');
        if (!res.ok) { const e = await res.json(); throw new Error(e.error || '导出失败'); }
        const blob = await res.blob();
        downloadBlob(blob, 'fridge-export-' + dateStamp() + '.csv');
        toast('CSV 导出成功');
    } catch (e) { toast(e.message, 'error'); }
});

// 导入 Markdown
document.getElementById('btn-import-md').addEventListener('click', () => {
    const content = document.getElementById('import-md-content').value.trim();
    if (!content) { toast('请粘贴 Markdown 内容', 'error'); return; }
    showConfirm('确定要导入 Markdown 数据吗？冰箱重名将跳过，物品全部新增。', async () => {
    try {
        const data = await api('import_md.php', {
            method: 'POST',
            body: JSON.stringify({ content })
        });
        const div = document.getElementById('import-md-result');
        if (data.errors && data.errors.length) {
            div.className = 'import-result error';
            div.innerHTML = '⚠️ 部分导入完成<br>'
                + '✅ 冰箱: ' + data.fridges_imported + ' 导入, ' + data.fridges_skipped + ' 跳过<br>'
                + '✅ 物品: ' + data.items_imported + ' 条新增<br>'
                + '❌ 错误:<br>' + data.errors.slice(0, 5).map(e => '· ' + esc(e)).join('<br>');
        } else {
            div.className = 'import-result success';
            div.innerHTML = '✅ 导入完成：冰箱 ' + data.fridges_imported + ' 导入, ' + data.fridges_skipped + ' 跳过；'
                + '物品 ' + data.items_imported + ' 条新增';
        }
        toast('导入完成');
    } catch (e) { toast(e.message, 'error'); }
    });
});

// 导入 SQL
document.getElementById('btn-import-sql').addEventListener('click', () => {
    const content = document.getElementById('import-sql-content').value.trim();
    if (!content) { toast('请粘贴 SQL 内容', 'error'); return; }
    showConfirm('确定要导入 SQL 数据吗？重名记录将跳过。', async () => {
    try {
        const data = await api('import_sql.php', {
            method: 'POST',
            body: JSON.stringify({ content })
        });
        const div = document.getElementById('import-sql-result');
        if (data.errors && data.errors.length) {
            div.className = 'import-result error';
            div.innerHTML = '⚠️ 导入完成（有错误）<br>'
                + '✅ 导入: ' + data.imported + ' 条<br>'
                + '❌ 错误:<br>' + data.errors.slice(0, 5).map(e => '· ' + esc(e)).join('<br>');
        } else {
            div.className = 'import-result success';
            div.innerHTML = '✅ 导入完成：共导入 ' + data.imported + ' 条记录';
        }
        toast('SQL 导入完成');
    } catch (e) { toast(e.message, 'error'); }
    });
});

// 下载 Markdown 空白模板
document.getElementById('btn-template-md').addEventListener('click', async () => {
    try {
        const res = await fetch('api/export_md.php?template=1');
        if (!res.ok) { const e = await res.json(); throw new Error(e.error || '下载失败'); }
        const blob = await res.blob();
        downloadBlob(blob, 'fridge-template.md');
        toast('空白模板已下载');
    } catch (e) { toast(e.message, 'error'); }
});

// 下载 CSV 空白模板
document.getElementById('btn-template-csv').addEventListener('click', async () => {
    try {
        const res = await fetch('api/export_csv.php?template=1');
        if (!res.ok) { const e = await res.json(); throw new Error(e.error || '下载失败'); }
        const blob = await res.blob();
        downloadBlob(blob, 'fridge-template.csv');
        toast('空白模板已下载');
    } catch (e) { toast(e.message, 'error'); }
});

// 导入 CSV
document.getElementById('btn-import-csv').addEventListener('click', () => {
    const content = document.getElementById('import-csv-content').value.trim();
    if (!content) { toast('请粘贴 CSV 内容', 'error'); return; }
    showConfirm('确定要导入 CSV 数据吗？冰箱重名将跳过，物品全部新增。', async () => {
    try {
        const data = await api('import_csv.php', {
            method: 'POST',
            body: JSON.stringify({ content })
        });
        const div = document.getElementById('import-csv-result');
        if (data.errors && data.errors.length) {
            div.className = 'import-result error';
            div.innerHTML = '⚠️ 部分导入完成<br>'
                + '✅ 冰箱: ' + data.fridges_imported + ' 导入, ' + data.fridges_skipped + ' 跳过<br>'
                + '✅ 物品: ' + data.items_imported + ' 条新增<br>'
                + '❌ 错误:<br>' + data.errors.slice(0, 5).map(e => '· ' + esc(e)).join('<br>');
        } else {
            div.className = 'import-result success';
            div.innerHTML = '✅ 导入完成：冰箱 ' + data.fridges_imported + ' 导入, ' + data.fridges_skipped + ' 跳过；'
                + '物品 ' + data.items_imported + ' 条新增';
        }
        toast('导入完成');
    } catch (e) { toast(e.message, 'error'); }
    });
});

// ========== 工具函数 ==========
function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

function dateStamp() {
    return new Date().toISOString().slice(0, 10).replace(/-/g, '');
}

// ========== Init ==========
loadDashboard();
