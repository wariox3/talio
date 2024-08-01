
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

