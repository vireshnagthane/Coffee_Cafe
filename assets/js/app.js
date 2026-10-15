(() => {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.getElementById('sidebar');

    if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('sidebar-open'));
    }

    const canvas = document.getElementById('sales-chart');
    if (!canvas) return;

    const labels = JSON.parse(canvas.dataset.labels || '[]');
    const values = JSON.parse(canvas.dataset.values || '[]').map(Number);
    const context = canvas.getContext('2d');
    if (!context) return;

    const drawChart = () => {
        const ratio = window.devicePixelRatio || 1;
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;
        if (!width || !height) return;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        context.scale(ratio, ratio);
        context.clearRect(0, 0, width, height);

        const padding = { top: 14, right: 12, bottom: 27, left: 38 };
        const chartWidth = width - padding.left - padding.right;
        const chartHeight = height - padding.top - padding.bottom;
        const maxValue = Math.max(...values, 1);
        const gridColor = '#eee9e2';
        context.font = '11px system-ui, sans-serif';
        context.textBaseline = 'middle';

        for (let line = 0; line <= 3; line++) {
            const y = padding.top + (chartHeight * line / 3);
            context.beginPath();
            context.strokeStyle = gridColor;
            context.lineWidth = 1;
            context.moveTo(padding.left, y);
            context.lineTo(width - padding.right, y);
            context.stroke();
            context.fillStyle = '#9a948c';
            context.textAlign = 'right';
            context.fillText(Math.round(maxValue * (3 - line) / 3).toString(), padding.left - 8, y);
        }

        const points = values.map((value, index) => ({
            x: padding.left + chartWidth * (values.length <= 1 ? 0.5 : index / (values.length - 1)),
            y: padding.top + chartHeight - (value / maxValue) * chartHeight,
        }));

        if (points.length > 1) {
            context.beginPath();
            context.moveTo(points[0].x, padding.top + chartHeight);
            points.forEach(point => context.lineTo(point.x, point.y));
            context.lineTo(points[points.length - 1].x, padding.top + chartHeight);
            context.closePath();
            const fill = context.createLinearGradient(0, padding.top, 0, height);
            fill.addColorStop(0, 'rgba(155, 98, 62, .20)');
            fill.addColorStop(1, 'rgba(155, 98, 62, .01)');
            context.fillStyle = fill;
            context.fill();
        }

        context.beginPath();
        points.forEach((point, index) => {
            if (index === 0) context.moveTo(point.x, point.y);
            else context.lineTo(point.x, point.y);
        });
        context.strokeStyle = '#9b623e';
        context.lineWidth = 2.5;
        context.lineJoin = 'round';
        context.stroke();

        points.forEach((point, index) => {
            context.beginPath();
            context.fillStyle = '#fff';
            context.strokeStyle = '#9b623e';
            context.lineWidth = 2;
            context.arc(point.x, point.y, 4, 0, Math.PI * 2);
            context.fill();
            context.stroke();
            context.fillStyle = '#8e8881';
            context.textAlign = 'center';
            context.textBaseline = 'top';
            context.fillText(labels[index] || '', point.x, height - padding.bottom + 9);
        });
    };

    drawChart();
    window.addEventListener('resize', drawChart);
})();

(() => {
    const pos = document.querySelector('[data-pos]');
    if (!pos) return;

    const cart = new Map();
    const list = pos.querySelector('[data-product-list]');
    const search = pos.querySelector('[data-product-search]');
    const category = pos.querySelector('[data-product-category]');
    const body = pos.querySelector('[data-cart-items]');
    const hiddenItems = pos.querySelector('[data-cart-json]');
    const subtotalLabel = pos.querySelector('[data-subtotal]');
    const totalLabel = pos.querySelector('[data-final-total]');
    const taxRate = Number(pos.dataset.taxRate || 0);
    const discountField = pos.querySelector('[data-discount]');
    const adminDiscount = pos.dataset.canDiscount === 'yes';
    const formatter = new Intl.NumberFormat('en-IN', { style: 'currency', currency: pos.dataset.currency || 'INR' });
    const money = value => formatter.format(value);

    const render = () => {
        if (!body) return;
        body.replaceChildren();
        let subtotalPaise = 0;
        cart.forEach(item => {
            const row = document.createElement('div');
            row.className = 'cart-row';
            const title = document.createElement('strong');
            title.textContent = item.name;
            const line = document.createElement('span');
            line.className = 'cart-line-total';
            line.textContent = money(item.price * item.quantity / 100);
            const quantity = document.createElement('div');
            quantity.className = 'quantity-control';
            const minus = document.createElement('button');
            minus.type = 'button';
            minus.textContent = '−';
            minus.setAttribute('aria-label', `Decrease ${item.name}`);
            minus.addEventListener('click', () => changeQuantity(item.id, -1));
            const amount = document.createElement('span');
            amount.textContent = String(item.quantity);
            const plus = document.createElement('button');
            plus.type = 'button';
            plus.textContent = '+';
            plus.setAttribute('aria-label', `Increase ${item.name}`);
            plus.addEventListener('click', () => changeQuantity(item.id, 1));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'remove-item';
            remove.textContent = '×';
            remove.setAttribute('aria-label', `Remove ${item.name}`);
            remove.addEventListener('click', () => { cart.delete(item.id); render(); });
            quantity.append(minus, amount, plus);
            row.append(title, line, quantity, remove);
            body.append(row);
            subtotalPaise += item.price * item.quantity;
        });

        if (cart.size === 0) {
            const empty = document.createElement('p');
            empty.className = 'cart-empty';
            empty.textContent = 'Your cart is empty. Add a drink to get started.';
            body.append(empty);
        }

        const discountRupees = adminDiscount ? Math.max(0, Number(discountField?.value || 0)) : 0;
        const discountPaise = Math.min(subtotalPaise, Math.round(discountRupees * 100));
        const taxablePaise = Math.max(0, subtotalPaise - discountPaise);
        const taxPaise = Math.round(taxablePaise * taxRate / 100);
        const taxLabel = pos.querySelector('[data-tax-total]');
        if (taxLabel) taxLabel.textContent = money(taxPaise / 100);
        subtotalLabel.textContent = money(subtotalPaise / 100);
        totalLabel.textContent = money((taxablePaise + taxPaise) / 100);
        hiddenItems.value = JSON.stringify([...cart.values()].map(item => ({ product_id: item.id, quantity: item.quantity })));
        const submit = pos.querySelector('[data-complete-order]');
        if (submit) submit.disabled = cart.size === 0;
    };

    const changeQuantity = (id, change) => {
        const item = cart.get(id);
        if (!item) return;
        item.quantity += change;
        if (item.quantity < 1) cart.delete(id);
        if (item.quantity > 999) item.quantity = 999;
        render();
    };

    list?.addEventListener('click', event => {
        const button = event.target.closest('[data-add-product]');
        if (!button) return;
        const id = button.dataset.id;
        const current = cart.get(id);
        if (current) changeQuantity(id, 1);
        else {
            cart.set(id, { id: Number(id), name: button.dataset.name, price: Math.round(Number(button.dataset.price) * 100), quantity: 1 });
            render();
        }
    });

    const filterProducts = () => {
        const term = (search?.value || '').trim().toLowerCase();
        const selectedCategory = category?.value || '';
        list?.querySelectorAll('[data-product-card]').forEach(card => {
            const matchesName = card.dataset.name.toLowerCase().includes(term);
            const matchesCategory = !selectedCategory || card.dataset.category === selectedCategory;
            card.hidden = !(matchesName && matchesCategory);
        });
    };

    search?.addEventListener('input', filterProducts);
    category?.addEventListener('change', filterProducts);
    discountField?.addEventListener('input', render);
    pos.addEventListener('submit', event => {
        if (cart.size === 0) {
            event.preventDefault();
            return;
        }
        const submit = pos.querySelector('[data-complete-order]');
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Saving order…';
        }
    });
    render();
})();

(() => {
    const form = document.querySelector('[data-purchase-form]');
    if (!form) return;

    const ingredients = JSON.parse(form.dataset.ingredients || '[]');
    const lines = form.querySelector('[data-purchase-lines]');
    const hidden = form.querySelector('[data-purchase-json]');
    const totalOutput = form.querySelector('[data-purchase-total]');
    const submit = form.querySelector('[data-purchase-submit]');
    const formatter = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' });

    const addLine = () => {
        if (ingredients.length === 0) return;
        const row = document.createElement('div');
        row.className = 'purchase-line';
        const ingredientSelect = document.createElement('select');
        ingredientSelect.setAttribute('aria-label', 'Ingredient');
        ingredientSelect.required = true;
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Choose ingredient';
        ingredientSelect.append(placeholder);
        ingredients.forEach(ingredient => {
            const option = document.createElement('option');
            option.value = String(ingredient.id);
            option.textContent = `${ingredient.name} (${ingredient.unit})`;
            ingredientSelect.append(option);
        });

        const quantity = document.createElement('input');
        quantity.type = 'number';
        quantity.min = '0.001';
        quantity.step = '0.001';
        quantity.placeholder = 'Quantity';
        quantity.setAttribute('aria-label', 'Quantity');

        const cost = document.createElement('input');
        cost.type = 'number';
        cost.min = '0';
        cost.step = '0.001';
        cost.placeholder = 'Unit cost';
        cost.setAttribute('aria-label', 'Unit cost in INR');

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'remove-purchase-line';
        remove.textContent = '×';
        remove.setAttribute('aria-label', 'Remove purchase line');
        remove.addEventListener('click', () => { row.remove(); update(); });

        [ingredientSelect, quantity, cost].forEach(control => {
            control.addEventListener('input', update);
            control.addEventListener('change', update);
        });
        row.append(ingredientSelect, quantity, cost, remove);
        lines.append(row);
        update();
    };

    const update = () => {
        const items = [];
        let total = 0;
        lines.querySelectorAll('.purchase-line').forEach(row => {
            const [select, quantityInput, costInput] = row.querySelectorAll('select, input');
            const quantityValue = Number(quantityInput.value);
            const costValue = Number(costInput.value);
            if (select.value && quantityValue > 0 && costValue >= 0) {
                items.push({
                    ingredient_id: Number(select.value),
                    quantity: quantityValue,
                    unit_cost: costValue,
                });
                total += quantityValue * costValue;
            }
        });
        hidden.value = JSON.stringify(items);
        totalOutput.textContent = formatter.format(total);
        submit.disabled = items.length === 0;
        form.querySelectorAll('.purchase-line select').forEach(select => {
            const selectedElsewhere = new Set([...items].map(item => String(item.ingredient_id)));
            [...select.options].forEach(option => {
                option.disabled = option.value !== '' && selectedElsewhere.has(option.value) && option.value !== select.value;
            });
        });
    };

    form.querySelector('[data-add-purchase-line]')?.addEventListener('click', addLine);
    form.addEventListener('submit', event => {
        if (JSON.parse(hidden.value).length === 0) {
            event.preventDefault();
            return;
        }
        submit.disabled = true;
        submit.textContent = 'Saving purchase…';
    });
    addLine();
})();
