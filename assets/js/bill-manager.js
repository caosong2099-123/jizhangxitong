class BillManager {
    constructor() {
        this.currentPage = 1;
        this.limit = 20;
        this.filters = {};
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadBills();
        this.loadStatistics();
    }

    bindEvents() {
        // 表单提交
        $('#bill-form').on('submit', (e) => this.handleAddBill(e));
        
        // 筛选条件变化
        $('.filter-input').on('change', () => this.handleFilterChange());
        
        // 分页
        $(document).on('click', '.page-link', (e) => this.handlePagination(e));
    }

    async handleAddBill(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);

        try {
            const response = await this.apiCall('/api/bill/add', 'POST', data);
            
            if (response.success) {
                this.showMessage('账单添加成功', 'success');
                e.target.reset();
                this.loadBills();
                this.loadStatistics();
            } else {
                this.showMessage(response.message, 'error');
            }
        } catch (error) {
            this.showMessage('网络错误，请重试', 'error');
            console.error('Add bill error:', error);
        }
    }

    async loadBills() {
        try {
            const params = new URLSearchParams({
                page: this.currentPage,
                limit: this.limit,
                ...this.filters
            });

            const response = await this.apiCall(`/api/bill/list?${params}`);
            
            if (response.success) {
                this.renderBills(response.data);
            }
        } catch (error) {
            console.error('Load bills error:', error);
        }
    }

    async loadStatistics() {
        try {
            const response = await this.apiCall('/api/bill/statistics');
            
            if (response.success) {
                this.renderStatistics(response.data);
            }
        } catch (error) {
            console.error('Load statistics error:', error);
        }
    }

    renderBills(bills) {
        const $container = $('#bills-container');
        
        if (bills.length === 0) {
            $container.html('<tr><td colspan="6" class="text-center">暂无数据</td></tr>');
            return;
        }

        const html = bills.map(bill => `
            <tr>
                <td>${bill.bill_date}</td>
                <td>${bill.category_name}</td>
                <td class="${bill.type === 'income' ? 'text-success' : 'text-danger'}">
                    ${bill.type === 'income' ? '+' : '-'}${bill.amount}
                </td>
                <td>${bill.description || '-'}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary edit-bill" data-id="${bill.id}">编辑</button>
                    <button class="btn btn-sm btn-outline-danger delete-bill" data-id="${bill.id}">删除</button>
                </td>
            </tr>
        `).join('');

        $container.html(html);
    }

    renderStatistics(statistics) {
        $('#income-total').text(statistics.income.total.toFixed(2));
        $('#income-count').text(statistics.income.count);
        $('#expense-total').text(statistics.expense.total.toFixed(2));
        $('#expense-count').text(statistics.expense.count);
    }

    handleFilterChange() {
        this.filters = {
            type: $('#filter-type').val(),
            category_id: $('#filter-category').val(),
            start_date: $('#filter-start-date').val(),
            end_date: $('#filter-end-date').val()
        };
        
        this.currentPage = 1;
        this.loadBills();
    }

    handlePagination(e) {
        e.preventDefault();
        const page = $(e.target).data('page');
        if (page) {
            this.currentPage = page;
            this.loadBills();
        }
    }

    async apiCall(url, method = 'GET', data = null) {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
            }
        };

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(url, options);
        return await response.json();
    }

    showMessage(message, type = 'info') {
        // 使用Toast或Alert显示消息
        alert(`${type.toUpperCase()}: ${message}`);
    }
}

// 页面加载完成后初始化
$(document).ready(() => {
    new BillManager();
});