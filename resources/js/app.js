const sidebar = document.querySelector('#module-sidebar');
const storage = {collapsed: 'module_sidebar_collapsed', active: 'active_module', groups: 'expanded_menu_groups'};
const savedCollapsed = window.localStorage.getItem(storage.collapsed) === 'true';
const reopen = document.querySelector('.reopen-sidebar');
const syncSidebarState = (collapsed) => {
    sidebar?.classList.toggle('is-collapsed', collapsed);
    if (reopen) reopen.style.display = collapsed ? 'block' : '';
};
syncSidebarState(savedCollapsed);
document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => button.addEventListener('click', () => {
    const collapsed = !sidebar.classList.contains('is-collapsed');
    syncSidebarState(collapsed);
    window.localStorage.setItem(storage.collapsed, String(collapsed));
}));
document.querySelectorAll('.group-toggle').forEach((button) => button.addEventListener('click', () => {
    const group = button.closest('.menu-group');
    const expanded = group.classList.toggle('is-closed') === false;
    button.setAttribute('aria-expanded', String(expanded));
    window.localStorage.setItem(storage.groups, JSON.stringify([...document.querySelectorAll('.menu-group')].filter((item) => !item.classList.contains('is-closed')).map((item) => item.dataset.menuGroup)));
}));
document.querySelectorAll('.rail-item').forEach((item) => item.addEventListener('click', () => window.localStorage.setItem(storage.active, document.body.dataset.module)));

const togglePanel = (selector) => document.querySelector(selector)?.classList.toggle('is-open');
document.querySelectorAll('[data-screen-options]').forEach((button) => button.addEventListener('click', () => togglePanel('[data-screen-panel]')));
document.querySelectorAll('[data-detail-settings]').forEach((button) => button.addEventListener('click', () => togglePanel('[data-detail-panel]')));
document.querySelectorAll('[data-column-toggle]').forEach((input) => input.addEventListener('change', () => document.querySelectorAll(`[data-column="${input.dataset.columnToggle}"]`).forEach((cell) => { cell.style.display = input.checked ? '' : 'none'; })));
document.querySelectorAll('[data-card-collapse]').forEach((button) => button.addEventListener('click', () => {
    const card = button.closest('[data-card]');
    card.classList.toggle('is-collapsed');
    window.localStorage.setItem(`erp_card_${card.dataset.card}`, String(card.classList.contains('is-collapsed')));
}));
document.querySelectorAll('[data-card]').forEach((card) => { if (window.localStorage.getItem(`erp_card_${card.dataset.card}`) === 'true') card.classList.add('is-collapsed'); });
document.querySelectorAll('[data-card-toggle]').forEach((input) => input.addEventListener('change', () => { const card = document.querySelector(`[data-card="${input.dataset.cardToggle}"]`); if (card) card.style.display = input.checked ? '' : 'none'; }));
document.querySelectorAll('[data-reset-layout]').forEach((button) => button.addEventListener('click', () => { document.querySelectorAll('[data-card]').forEach((card) => { card.classList.remove('is-collapsed'); card.style.display = ''; window.localStorage.removeItem(`erp_card_${card.dataset.card}`); }); document.querySelector('[data-detail-panel]')?.classList.remove('is-open'); }));
document.querySelectorAll('.copy-address,.secondary-button').forEach((button) => button.addEventListener('click', () => { if (button.textContent.includes('复制')) { navigator.clipboard?.writeText(button.closest('.card-body')?.innerText || ''); button.textContent = '✓ 已复制'; } }));
