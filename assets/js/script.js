// 通用JavaScript功能
document.addEventListener('DOMContentLoaded', function() {
    // 表单验证增强
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = '#e74c3c';
                } else {
                    field.style.borderColor = '';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('请填写所有必填字段');
            }
        });
    });
    
    // 数字输入框格式化
    const numberInputs = document.querySelectorAll('input[type="number"]');
    numberInputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value) {
                this.value = parseFloat(this.value).toFixed(2);
            }
        });
    });
    
    // 响应式导航菜单
    const navMenu = document.querySelector('.nav-menu');
    if (navMenu && window.innerWidth < 768) {
        // 在小屏幕上将导航菜单转换为下拉菜单
        const select = document.createElement('select');
        select.className = 'mobile-nav-select';
        
        navMenu.querySelectorAll('a').forEach(link => {
            const option = document.createElement('option');
            option.value = link.href;
            option.textContent = link.textContent;
            if (link.classList.contains('active')) {
                option.selected = true;
            }
            select.appendChild(option);
        });
        
        select.addEventListener('change', function() {
            window.location.href = this.value;
        });
        
        navMenu.parentNode.replaceChild(select, navMenu);
    }
    
    // 自动隐藏警告消息
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
    });
});

// 预算进度条动画
function animateProgressBars() {
    const progressBars = document.querySelectorAll('.progress-fill');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0';
        setTimeout(() => {
            bar.style.width = width;
        }, 100);
    });
}

// 图表初始化（简化实现）
function initCharts() {
    // 在实际应用中，这里会初始化Chart.js图表
    console.log('图表初始化功能');
}

// 导出函数供其他脚本使用
window.accountingSystem = {
    animateProgressBars,
    initCharts
};