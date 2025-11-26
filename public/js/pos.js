// public/js/pos.js
"use strict";

// =========================
// Helpers precio / formato
// =========================
function formatoPrecio(valor) {
    const num = Number(valor) || 0;
    return "$" + num.toLocaleString("es-AR", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

// =========================
// Estado en memoria
// =========================
const cart = new Map(); // id -> { id, name, price, qty }
let totalVenta = 0;
let listaPreciosActual = "MINORISTA"; // MINORISTA o MAYORISTA

// =========================
// Manejo del carrito
// =========================
function agregarAlCarritoDesdeCard(card) {
    const id = card.dataset.id;
    const name = card.dataset.name;
    const price = Number(card.dataset.price);

    if (!id || !name || isNaN(price)) return;

    const item = cart.get(id) || { id, name, price, qty: 0 };
    item.price = price;
    item.qty += 1;
    cart.set(id, item);

    renderCarrito();
}

function cambiarCantidadCarrito(id, delta) {
    const item = cart.get(id);
    if (!item) return;

    item.qty += delta;
    if (item.qty <= 0) {
        cart.delete(id);
    } else {
        cart.set(id, item);
    }
    renderCarrito();
}

function eliminarDelCarrito(id) {
    if (!cart.has(id)) return;
    cart.delete(id);
    renderCarrito();
}

function renderCarrito() {
    const contenedor = document.getElementById("pos-cart-items");
    const totalItemsEl = document.getElementById("pos-total-items");
    const netoEl = document.getElementById("pos-neto");
    const descuentoEl = document.getElementById("pos-descuento");
    const subtotalEl = document.getElementById("pos-subtotal");
    const sellTotalEl = document.getElementById("pos-sell-total");
    const sellBtn = document.getElementById("pos-open-payment");

    if (!contenedor) return;

    contenedor.innerHTML = "";

    let totalItems = 0;
    let total = 0;

    cart.forEach((item) => {
        const subtotal = item.price * item.qty;
        totalItems += item.qty;
        total += subtotal;

        const row = document.createElement("div");
        row.className = "pos-cart-item";
        row.innerHTML = `
            <div class="pos-cart-qty">
                <button type="button" class="pos-cart-btn pos-cart-btn--minus" data-id="${item.id}">-</button>
                <span class="pos-cart-qty-value">${item.qty}</span>
                <button type="button" class="pos-cart-btn pos-cart-btn--plus" data-id="${item.id}">+</button>
            </div>
            <div class="pos-cart-name">${item.name}</div>
            <div class="pos-cart-subtotal">${formatoPrecio(subtotal)}</div>
            <button type="button" class="pos-cart-remove" data-id="${item.id}">&times;</button>
        `;
        contenedor.appendChild(row);
    });

    totalVenta = total;

    totalItemsEl.textContent = totalItems;
    netoEl.textContent = formatoPrecio(total);
    descuentoEl.textContent = "$0,00";
    subtotalEl.textContent = formatoPrecio(total);
    sellTotalEl.textContent = formatoPrecio(total);

    sellBtn.disabled = total <= 0;
}

// =========================
// Cambio de lista
// =========================
function actualizarPreciosPorLista(lista) {
    const cards = document.querySelectorAll(".pos-product-card");

    cards.forEach((card) => {
        const priceMin = Number(card.dataset.priceMinorista || 0);
        const priceMay = Number(card.dataset.priceMayorista || 0);

        let price = lista === "MAYORISTA" ? priceMay : priceMin;
        if (!isFinite(price)) price = 0;

        card.dataset.price = String(price);

        const priceEl = card.querySelector(".pos-product-price");
        if (priceEl) priceEl.textContent = formatoPrecio(price);
    });

    cart.clear();
    renderCarrito();
}

// =========================
// Buscador
// =========================
function initBuscadorProductos() {
    const input = document.getElementById("pos-search-input");
    if (!input) return;

    const cards = Array.from(document.querySelectorAll(".pos-product-card"));

    input.addEventListener("input", () => {
        const texto = input.value.toLowerCase().trim();
        cards.forEach((card) => {
            const name = card.dataset.name.toLowerCase();
            const code = (card.dataset.code || "").toLowerCase();
            const coincide = !texto || name.includes(texto) || code.includes(texto);
            card.style.display = coincide ? "" : "none";
        });
    });
}

// =========================
// Configuración
// =========================
function abrirConfigModal() {
    document.body.classList.add("pos-modal-open");
    const modal = document.getElementById("pos-config-modal");
    modal.classList.add("pos-modal--open");
}

function cerrarConfigModal() {
    document.body.classList.remove("pos-modal-open");
    const modal = document.getElementById("pos-config-modal");
    modal.classList.remove("pos-modal--open");
}

function initConfigModal() {
    const btnOpen = document.getElementById("pos-open-config");
    const modal = document.getElementById("pos-config-modal");
    const btnSave = document.getElementById("pos-save-config");

    btnOpen.addEventListener("click", abrirConfigModal);
    modal.querySelectorAll("[data-close-config]").forEach((el) =>
        el.addEventListener("click", cerrarConfigModal)
    );

    btnSave.addEventListener("click", () => {
        const tipoVenta = document.getElementById("pos-tipo-venta").value;
        const listaPrecios = document.getElementById("pos-lista-precios").value;

        listaPreciosActual = listaPrecios;

        actualizarPreciosPorLista(listaPrecios);
        cerrarConfigModal();
    });
}

// =========================
// Modal de Pago
// =========================
function crearFilaPago(metodo = "EFECTIVO", monto = 0) {
    const div = document.createElement("div");
    div.className = "pos-payment-row";
    div.innerHTML = `
        <select class="pos-select pos-payment-method">
            <option value="EFECTIVO">Efectivo</option>
            <option value="TARJETA">Tarjeta</option>
            <option value="TRANSFERENCIA">Transferencia</option>
            <option value="QR">QR</option>
        </select>
        <input type="number" class="pos-input pos-payment-amount" value="${monto}">
        <button type="button" class="pos-payment-remove">&times;</button>
    `;
    return div;
}

function calcularPagos() {
    const filas = Array.from(document.querySelectorAll(".pos-payment-row"));
    let totalAbonado = 0;

    filas.forEach((row) => {
        totalAbonado += Number(row.querySelector(".pos-payment-amount").value) || 0;
    });

    document.getElementById("pos-pay-total").textContent = formatoPrecio(totalVenta);
    document.getElementById("pos-pay-abonado").textContent = formatoPrecio(totalAbonado);
    document.getElementById("pos-pay-saldo").textContent = formatoPrecio(totalVenta - totalAbonado);

    return { totalAbonado, saldo: totalVenta - totalAbonado };
}

function abrirPaymentModal() {
    if (totalVenta <= 0) return;

    document.body.classList.add("pos-modal-open");

    const modal = document.getElementById("pos-payment-modal");
    const list = document.getElementById("pos-payments-list");

    list.innerHTML = "";
    list.appendChild(crearFilaPago("EFECTIVO", totalVenta));
    calcularPagos();

    modal.classList.add("pos-modal--open");
}

function cerrarPaymentModal() {
    document.body.classList.remove("pos-modal-open");
    document.getElementById("pos-payment-modal").classList.remove("pos-modal--open");
}

function initPaymentModal() {
    const modal = document.getElementById("pos-payment-modal");
    const list = document.getElementById("pos-payments-list");

    document.getElementById("pos-open-payment").addEventListener("click", abrirPaymentModal);

    modal.querySelectorAll("[data-close-payment]").forEach((el) =>
        el.addEventListener("click", cerrarPaymentModal)
    );

    document.getElementById("pos-add-payment").addEventListener("click", () => {
        list.appendChild(crearFilaPago("EFECTIVO", 0));
        calcularPagos();
    });

    list.addEventListener("input", () => calcularPagos());

    list.addEventListener("click", (e) => {
        if (e.target.classList.contains("pos-payment-remove")) {
            e.target.closest(".pos-payment-row").remove();
            calcularPagos();
        }
    });

    document.getElementById("pos-confirm-payment").addEventListener("click", () => {
        const { totalAbonado, saldo } = calcularPagos();

        if (totalAbonado > totalVenta) {
            alert("El monto abonado no puede ser mayor al total.");
            return;
        }

        const tipo_venta = document.getElementById("pos-tipo-venta").value;
        const lista_precios = listaPreciosActual;
        const cliente = document.getElementById("pos-client-input").value;

        // Armamos pagos
        const pagosArray = Array.from(document.querySelectorAll(".pos-payment-row")).map(row => ({
            metodo: row.querySelector(".pos-payment-method").value,
            monto: Number(row.querySelector(".pos-payment-amount").value)
        }));

        const payload = {
            tipo_venta,
            lista_precios,
            total_venta: totalVenta,
            total_abonado: totalAbonado,
            saldo,
            carrito: Array.from(cart.values()),
            pagos: pagosArray,
            cliente
        };

        fetch("/TYPSISTEMA/app/controllers/ventas/guardar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert("Venta guardada correctamente. ID: " + data.id_venta);
                window.location.reload();
            } else {
                alert("Error: " + data.error);
                console.log(data);
            }
        });

        cerrarPaymentModal();
    });
}

// =========================
// Inicialización
// =========================
document.addEventListener("DOMContentLoaded", () => {
    if (!document.querySelector(".pos-container")) return;

    document.querySelectorAll(".pos-product-card").forEach((card) => {
        card.addEventListener("click", () => agregarAlCarritoDesdeCard(card));
    });

    const cartContainer = document.getElementById("pos-cart-items");
    cartContainer.addEventListener("click", (e) => {
        const id = e.target.dataset.id;
        if (!id) return;

        if (e.target.classList.contains("pos-cart-btn--plus")) cambiarCantidadCarrito(id, 1);
        else if (e.target.classList.contains("pos-cart-btn--minus")) cambiarCantidadCarrito(id, -1);
        else if (e.target.classList.contains("pos-cart-remove")) eliminarDelCarrito(id);
    });

    initBuscadorProductos();
    initConfigModal();
    initPaymentModal();
    actualizarPreciosPorLista(listaPreciosActual);
    renderCarrito();
});