
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

