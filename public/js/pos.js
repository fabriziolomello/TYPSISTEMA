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
// cart: idVariante -> { idVariante, idProducto, name, price, qty, discountPct }
const cart = new Map();
let totalVenta = 0; // total final con todos los descuentos
let listaPreciosActual = "MINORISTA"; // MINORISTA o MAYORISTA
let descuentoGlobalPct = 0;
let descuentoGlobalMonto = 0;

// =========================
// Manejo del carrito
// =========================
function agregarAlCarritoDesdeCard(card) {
    const idVariante = card.dataset.idVariante;
    const idProducto = card.dataset.idProducto;
    const name       = card.dataset.name;
    const price      = Number(card.dataset.price);

    if (!idVariante || !idProducto || !name || isNaN(price)) return;

    const existing = cart.get(idVariante) || {
        idVariante,
        idProducto,
        name,
        price,
        qty: 0,
        discountPct: 0,
    };

    existing.price = price; // precio actual según lista
    existing.qty   += 1;

    cart.set(idVariante, existing);
    renderCarrito();
}

function eliminarDelCarrito(idVariante) {
    if (!cart.has(idVariante)) return;
    cart.delete(idVariante);
    renderCarrito();
}

/**
 * Recalcula los totales generales (neto, descuentos, subtotal, botón vender)
 * sin tocar las filas del carrito (no re-renderiza inputs).
 */
function recalcularTotalesGlobales() {
    const totalItemsEl    = document.getElementById("pos-total-items");
    const netoEl          = document.getElementById("pos-neto");
    const descuentoEl     = document.getElementById("pos-descuento");
    const subtotalEl      = document.getElementById("pos-subtotal");
    const sellTotalEl     = document.getElementById("pos-sell-total");
    const sellBtn         = document.getElementById("pos-open-payment");
    const pctInput        = document.getElementById("pos-desc-global-pct");
    const montoInput      = document.getElementById("pos-desc-global-monto");

    let totalItems = 0;
    let netoBase = 0;             // suma sin ningún descuento
    let totalConDescLineas = 0;   // suma con descuentos por ítem

    cart.forEach((item) => {
        const qty = Number(item.qty) || 0;
        const price = Number(item.price) || 0;
        const discount = Number(item.discountPct) || 0;

        const lineNeto = price * qty;
        const factorDescLinea = 1 - discount / 100;
        const lineConDesc = lineNeto * factorDescLinea;

        totalItems += qty;
        netoBase += lineNeto;
        totalConDescLineas += lineConDesc;
    });

    // --------- Descuento global ----------
    let pctVal = descuentoGlobalPct;
    let montoVal = descuentoGlobalMonto;

    if (pctInput) {
        pctVal = Number(pctInput.value) || 0;
        if (pctVal < 0) pctVal = 0;
        if (pctVal > 100) pctVal = 100;
        pctInput.value = pctVal;
    }

    if (montoInput) {
        montoVal = Number(montoInput.value) || 0;
        if (montoVal < 0) montoVal = 0;
        if (montoVal > totalConDescLineas) montoVal = totalConDescLineas;
        montoInput.value = montoVal;
    }

    let descuentoGlobalAplicado = 0;

    if (montoVal > 0) {
        descuentoGlobalMonto = montoVal;
        descuentoGlobalPct   = 0;
        if (pctInput) pctInput.value = 0;
        descuentoGlobalAplicado = montoVal;
    } else if (pctVal > 0) {
        descuentoGlobalPct   = pctVal;
        descuentoGlobalMonto = 0;
        if (montoInput) montoInput.value = 0;
        descuentoGlobalAplicado = totalConDescLineas * (pctVal / 100);
    } else {
        descuentoGlobalPct   = 0;
        descuentoGlobalMonto = 0;
        descuentoGlobalAplicado = 0;
    }

    let totalDespuesGlobal = totalConDescLineas - descuentoGlobalAplicado;
    if (totalDespuesGlobal < 0) totalDespuesGlobal = 0;

    const totalDescuento = netoBase - totalDespuesGlobal;
    totalVenta = totalDespuesGlobal;

    if (totalItemsEl) totalItemsEl.textContent = totalItems;
    if (netoEl)       netoEl.textContent       = formatoPrecio(netoBase);
    if (descuentoEl)  descuentoEl.textContent  = formatoPrecio(totalDescuento);
    if (subtotalEl)   subtotalEl.textContent   = formatoPrecio(totalDespuesGlobal);
    if (sellTotalEl)  sellTotalEl.textContent  = formatoPrecio(totalDespuesGlobal);
    if (sellBtn)      sellBtn.disabled         = totalDespuesGlobal <= 0;
}

/**
 * Actualiza un item a partir de la fila editada,
 * actualiza el subtotal de ESA fila y los totales generales.
 */
function actualizarItemDesdeFila(row) {
    const idVariante = row.dataset.id;
    const item = cart.get(idVariante);
    if (!item) return;

    let qty = parseFloat(row.querySelector(".pos-cart-qty").value) || 0;
    let price = parseFloat(row.querySelector(".pos-cart-price").value) || 0;
    let discount = parseFloat(row.querySelector(".pos-cart-discount").value) || 0;

    if (qty <= 0) {
        cart.delete(idVariante);
        renderCarrito();
        return;
    }

    if (price < 0) price = 0;
    if (discount < 0) discount = 0;
    if (discount > 100) discount = 100;

    item.qty = qty;
    item.price = price;
    item.discountPct = discount;
    cart.set(idVariante, item);

    const lineNeto = price * qty;
    const factorDesc = 1 - discount / 100;
    const lineConDesc = lineNeto * factorDesc;

    const subtotalSpan = row.querySelector(".pos-cart-subtotal-text");
    if (subtotalSpan) {
        subtotalSpan.textContent = formatoPrecio(lineConDesc);
    }

    recalcularTotalesGlobales();
}

/**
 * Render completo del carrito
 */
function renderCarrito() {
    const contenedor = document.getElementById("pos-cart-items");
    if (!contenedor) return;

    contenedor.innerHTML = "";

    cart.forEach((item) => {
        const qty = Number(item.qty) || 0;
        const price = Number(item.price) || 0;
        const discount = Number(item.discountPct) || 0;

        const lineNeto = price * qty;
        const factorDesc = 1 - discount / 100;
        const lineConDesc = lineNeto * factorDesc;

        const row = document.createElement("div");
        row.className = "pos-cart-item";
        row.dataset.id = item.idVariante;

        row.innerHTML = `
            <div class="pos-cart-top">
                <span class="pos-cart-name">${item.name}</span>
                <button
                    type="button"
                    class="pos-cart-remove"
                    data-id="${item.idVariante}"
                >&times;</button>
            </div>

            <div class="pos-cart-row">
                <div class="pos-cart-field">
                    <span class="pos-cart-field-label">Cant.</span>
                    <input
                        type="number"
                        class="pos-cart-input pos-cart-qty"
                        min="1"
                        value="${qty}"
                    >
                </div>

                <div class="pos-cart-field">
                    <span class="pos-cart-field-label">Precio</span>
                    <input
                        type="number"
                        class="pos-cart-input pos-cart-price"
                        step="0.01"
                        min="0"
                        value="${price}"
                    >
                </div>

                <div class="pos-cart-field">
                    <span class="pos-cart-field-label">Desc. %</span>
                    <input
                        type="number"
                        class="pos-cart-input pos-cart-discount"
                        step="1"
                        min="0"
                        max="100"
                        value="${discount}"
                    >
                </div>
            </div>

            <div class="pos-cart-bottom">
                <span class="pos-cart-bottom-label">Subtotal</span>
                <span class="pos-cart-subtotal-text">
                    ${formatoPrecio(lineConDesc)}
                </span>
            </div>
        `;

        contenedor.appendChild(row);
    });

    recalcularTotalesGlobales();
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

    // No vaciamos el carrito: sólo afecta nuevos productos
    recalcularTotalesGlobales();
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
            <option value="EFECTIVO"${metodo === "EFECTIVO" ? " selected" : ""}>Efectivo</option>
            <option value="TARJETA"${metodo === "TARJETA" ? " selected" : ""}>Tarjeta</option>
            <option value="TRANSFERENCIA"${metodo === "TRANSFERENCIA" ? " selected" : ""}>Transferencia</option>
            <option value="QR"${metodo === "QR" ? " selected" : ""}>QR</option>
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

        // ---- Carrito con prorrateo de descuento global ----
        const items = Array.from(cart.values());
        let totalLineasConDesc = 0;

        const lineasBase = items.map(item => {
            const qty       = Number(item.qty) || 0;
            const price     = Number(item.price) || 0;
            const descPct   = Number(item.discountPct) || 0;
            const lineNeto  = price * qty;
            const lineDesc  = lineNeto * (1 - descPct / 100);
            totalLineasConDesc += lineDesc;
            return { item, qty, price, descPct, lineDesc };
        });

        let factorGlobal = 1;
        if (totalLineasConDesc > 0 && totalVenta > 0) {
            factorGlobal = totalVenta / totalLineasConDesc;
        }

        const carritoArray = lineasBase.map(({ item, qty, price, descPct, lineDesc }) => {
            const subtotalFinal = lineDesc * factorGlobal;
            return {
                id_producto: item.idProducto,
                id_variante: item.idVariante,
                nombre: item.name,
                cantidad: qty,
                precio_unitario: price,
                descuento_porcentaje: descPct,
                subtotal: subtotalFinal
            };
        });

        const payload = {
            tipo_venta,
            lista_precios,
            total_venta: totalVenta,
            total_abonado: totalAbonado,
            saldo,
            descuento_global_porcentaje: descuentoGlobalPct,
            descuento_global_monto: descuentoGlobalMonto,
            carrito: carritoArray,
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
                cerrarPaymentModal();
                abrirSuccessModal(data.id_venta);
            } else {
                alert("Error: " + data.error);
                console.log(data);
            }
        });
    });
}

// =========================
// Modal de éxito (Venta registrada)
// =========================
// =========================
// Modal de éxito (Venta registrada)
// =========================
function abrirSuccessModal(idVenta) {
    const modal = document.getElementById("pos-success-modal");
    if (!modal) {
        // Fallback raro: si no existe el modal, recargamos
        window.location.reload();
        return;
    }

    // Guardamos el id de la venta en un data-attribute del modal
    modal.dataset.idVenta = idVenta ?? "";

    const idSpan = document.getElementById("pos-success-id");
    if (idSpan) {
        idSpan.textContent = idVenta ?? "-";
    }

    document.body.classList.add("pos-modal-open");
    modal.classList.add("pos-modal--open");
}

function cerrarSuccessModal() {
    const modal = document.getElementById("pos-success-modal");
    if (!modal) return;
    modal.classList.remove("pos-modal--open");
    document.body.classList.remove("pos-modal-open");
}

function initSuccessModal() {
    const modal = document.getElementById("pos-success-modal");
    if (!modal) return;

    const btnClose   = document.getElementById("pos-success-close");
    const btnNewSale = document.getElementById("pos-success-new-sale");
    const btnPrint   = document.getElementById("pos-success-print");

    // Cerrar clickeando el fondo (si querés mantenerlo)
    modal.querySelectorAll("[data-close-success]").forEach(el =>
        el.addEventListener("click", () => {
            // Reiniciamos POS igual que Cerrar
            cart.clear();
            window.location.reload();
        })
    );

    if (btnClose) {
        btnClose.addEventListener("click", () => {
            // Cerrar = terminar venta y reiniciar POS
            cart.clear();
            window.location.reload();
        });
    }

    if (btnNewSale) {
        btnNewSale.addEventListener("click", () => {
            // Nueva venta = también recargamos todo
            cart.clear();
            window.location.reload();
        });
    }

    if (btnPrint) {
        btnPrint.addEventListener("click", () => {
            const idVenta = modal.dataset.idVenta;
            if (!idVenta) return;

            // Abrimos el ticket en una nueva pestaña/ventana
            window.open(
                `/TYPSISTEMA/app/views/ventas/imprimir.php?id=${idVenta}`,
                "_blank"
            );
        });
    }
}

// =========================
// Inicialización
// =========================
document.addEventListener("DOMContentLoaded", () => {
    if (!document.querySelector(".pos-container")) return;

    // Click en cards de producto -> agregar al carrito
    document.querySelectorAll(".pos-product-card").forEach((card) => {
        card.addEventListener("click", () => agregarAlCarritoDesdeCard(card));
    });

    // Delegación de eventos en el carrito
    const cartContainer = document.getElementById("pos-cart-items");

    // Inputs (cantidad, precio, descuento)
    cartContainer.addEventListener("input", (e) => {
        if (
            e.target.classList.contains("pos-cart-qty") ||
            e.target.classList.contains("pos-cart-price") ||
            e.target.classList.contains("pos-cart-discount")
        ) {
            const row = e.target.closest(".pos-cart-item");
            if (row) actualizarItemDesdeFila(row);
        }
    });

    // Botón eliminar
    cartContainer.addEventListener("click", (e) => {
        if (e.target.classList.contains("pos-cart-remove")) {
            const idVariante = e.target.dataset.id;
            if (idVariante) eliminarDelCarrito(idVariante);
        }
    });

    // Descuento total (% y $)
    const pctInput = document.getElementById("pos-desc-global-pct");
    const montoInput = document.getElementById("pos-desc-global-monto");

    if (pctInput) {
        pctInput.addEventListener("input", () => {
            recalcularTotalesGlobales();
        });
    }

    if (montoInput) {
        montoInput.addEventListener("input", () => {
            recalcularTotalesGlobales();
        });
    }

    initBuscadorProductos();
    initConfigModal();
    initPaymentModal();
    initSuccessModal();

    actualizarPreciosPorLista(listaPreciosActual);
    renderCarrito();
});