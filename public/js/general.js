
function abrirVentana(url, Nombre, Alto, Ancho) {
    var randomnumber = Math.floor((Math.random()*100)+1);
    window.open(url,Nombre + "-" +randomnumber, 'width=' + Ancho + ', height=' + Alto +',scrollbars=1,menubar=0,resizable=1');
}

function ChequearTodosTabla(source, nombre) {
    // Obtener todos los checkboxes con el nombre dado
    const checkboxes = document.querySelectorAll(`input[name="${nombre}"]`);
    // Determinar si todos los checkboxes están marcados
    const allChecked = Array.from(checkboxes).every(checkbox => checkbox.checked);

    // Si todos están marcados, desmarcar todos, de lo contrario, marcar todos
    checkboxes.forEach(checkbox => {
        checkbox.checked = !allChecked ? source.checked : false;
    });
}

// Pestañas. El botón dice con data-panel qué panel abre, y el panel se busca
// por su id dentro del mismo contenedor .tabs: así puede haber varios grupos
// de pestañas en una página sin pisarse. El listener va en el documento para
// que valga también con contenido añadido después.
document.addEventListener('click', evento => {
    const boton = evento.target.closest('.tabs-head .tab');
    if (!boton) {
        return;
    }

    const grupo = boton.closest('.tabs');
    grupo.querySelectorAll('.tabs-head .tab').forEach(pestana => {
        const activa = pestana === boton;
        pestana.classList.toggle('is-active', activa);
        pestana.setAttribute('aria-selected', activa);
    });
    grupo.querySelectorAll(':scope > .tab-panel').forEach(panel => {
        panel.hidden = panel.id !== boton.dataset.panel;
    });
});

// Abre la pestaña que nombre el fragmento de la URL (#webhooks), para volver a
// ella tras una acción que redirige a la página.
document.addEventListener('DOMContentLoaded', () => {
    const id = decodeURIComponent(window.location.hash.slice(1));
    if (!id) {
        return;
    }
    document.querySelector(`.tabs-head .tab[data-panel="${CSS.escape(id)}"]`)?.click();
});

// Menus desplegables (<details class="menu">): se cierran al hacer clic fuera
// o con Escape, que <details> por si solo no hace.
document.addEventListener('click', evento => {
    document.querySelectorAll('details.menu[open]').forEach(menu => {
        if (!menu.contains(evento.target)) {
            menu.open = false;
        }
    });
});
document.addEventListener('keydown', evento => {
    if (evento.key === 'Escape') {
        document.querySelectorAll('details.menu[open]').forEach(menu => { menu.open = false; });
    }
});

// Menu lateral en pantallas angostas: el boton lo abre y lo cierra. Escape
// tambien lo cierra. En escritorio el boton no se ve y esto no hace nada.
document.addEventListener('click', evento => {
    const boton = evento.target.closest('.nav-toggle');
    if (!boton) {
        return;
    }
    const abierto = boton.closest('.side').classList.toggle('is-open');
    boton.setAttribute('aria-expanded', abierto);
    boton.setAttribute('aria-label', abierto ? 'Cerrar menú' : 'Abrir menú');
});
document.addEventListener('keydown', evento => {
    const lateral = document.querySelector('.side.is-open');
    if (evento.key === 'Escape' && lateral) {
        lateral.classList.remove('is-open');
        const boton = lateral.querySelector('.nav-toggle');
        boton.setAttribute('aria-expanded', 'false');
        boton.setAttribute('aria-label', 'Abrir menú');
        boton.focus();
    }
});
