/**
 * Gráfica de área para una sola serie. Sustituye a Chart.js.
 *
 * El viewBox se recalcula para que coincida en píxeles con el ancho real del
 * contenedor. Así el factor de escala del SVG es 1 y el texto no se deforma:
 * con un viewBox fijo y preserveAspectRatio="none" las letras se estiran en
 * horizontal tanto como se haya estirado el lienzo.
 *
 * Uso:
 *   <div class="chart-wrap">
 *     <svg data-grafica
 *          data-labels='["00","01"]'
 *          data-valores='[12,8]'
 *          data-unidad="doc"></svg>
 *     <div class="chart-tip"></div>
 *   </div>
 */
(function () {
    var NS = "http://www.w3.org/2000/svg";
    var ALTO = 170;
    var ANCHO_MINIMO = 280;

    function crear(nombre, atributos) {
        var nodo = document.createElementNS(NS, nombre);
        for (var clave in atributos) {
            nodo.setAttribute(clave, atributos[clave]);
        }
        return nodo;
    }

    /** Techo "redondo" por encima del máximo, para que el eje no quede pegado. */
    function techo(maximo) {
        if (maximo <= 0) {
            return 10;
        }
        var magnitud = Math.pow(10, Math.floor(Math.log(maximo) / Math.LN10));
        return Math.ceil(maximo / (magnitud / 2)) * (magnitud / 2);
    }

    function preparar(svg) {
        var etiquetas, valores;
        try {
            etiquetas = JSON.parse(svg.getAttribute("data-labels") || "[]");
            valores = JSON.parse(svg.getAttribute("data-valores") || "[]");
        } catch (e) {
            return;
        }

        var contenedor = svg.parentNode;
        if (valores.length < 2) {
            contenedor.innerHTML = '<p class="hint">Sin datos suficientes para la gráfica.</p>';
            return;
        }

        var unidad = svg.getAttribute("data-unidad") || "";
        var globo = contenedor.querySelector(".chart-tip");
        var maximoReal = Math.max.apply(null, valores);
        var maximo = techo(maximoReal);
        var ultimo = valores.length - 1;

        svg.setAttribute("role", "img");
        svg.setAttribute(
            "aria-label",
            "Serie de " + valores.length + " puntos, máximo " + maximoReal + (unidad ? " " + unidad : "")
        );
        svg.style.display = "block";
        svg.style.width = "100%";
        svg.style.height = ALTO + "px";

        /* Geometría vigente. La recalcula pintar() y la leen los manejadores
           de ratón, que se registran una sola vez. */
        var geo = null;

        function pintar() {
            var ancho = Math.round(contenedor.clientWidth || svg.clientWidth || 800);
            if (ancho < ANCHO_MINIMO) {
                ancho = ANCHO_MINIMO;
            }

            var padL = 34, padR = 10, padT = 12, padB = 22;
            var anchoUtil = ancho - padL - padR;
            var altoUtil = ALTO - padT - padB;

            /* viewBox == tamaño real en píxeles => escala 1:1, texto sin deformar. */
            svg.setAttribute("viewBox", "0 0 " + ancho + " " + ALTO);
            svg.removeAttribute("preserveAspectRatio");

            while (svg.firstChild) {
                svg.removeChild(svg.firstChild);
            }

            function x(i) { return padL + (i / ultimo) * anchoUtil; }
            function y(v) { return padT + altoUtil - (v / maximo) * altoUtil; }

            [0, maximo / 2, maximo].forEach(function (v) {
                svg.appendChild(crear("line", {
                    x1: padL, x2: ancho - padR, y1: y(v), y2: y(v),
                    stroke: "var(--line)", "stroke-width": 1
                }));
                var texto = crear("text", {
                    x: padL - 6, y: y(v) + 3.4, "text-anchor": "end",
                    fill: "var(--muted)", "font-size": 10,
                    "font-family": "IBM Plex Mono, ui-monospace, monospace"
                });
                texto.textContent = v;
                svg.appendChild(texto);
            });

            /* Cuántas etiquetas caben: ~34 px por etiqueta en el eje X. */
            var salto = Math.max(1, Math.ceil(etiquetas.length / Math.max(2, Math.floor(anchoUtil / 34))));
            etiquetas.forEach(function (etiqueta, i) {
                if (i % salto !== 0) {
                    return;
                }
                var texto = crear("text", {
                    x: x(i), y: ALTO - 6, "text-anchor": "middle",
                    fill: "var(--muted)", "font-size": 10,
                    "font-family": "IBM Plex Mono, ui-monospace, monospace"
                });
                texto.textContent = etiqueta;
                svg.appendChild(texto);
            });

            var linea = valores.map(function (v, i) {
                return (i ? "L" : "M") + x(i).toFixed(1) + " " + y(v).toFixed(1);
            }).join(" ");
            var area = linea + " L" + x(ultimo).toFixed(1) + " " + y(0) + " L" + x(0).toFixed(1) + " " + y(0) + " Z";

            var idGradiente = "areaTalio" + Math.random().toString(36).slice(2, 8);
            var defs = crear("defs", {});
            var gradiente = crear("linearGradient", { id: idGradiente, x1: 0, y1: 0, x2: 0, y2: 1 });
            gradiente.appendChild(crear("stop", { offset: "0%", "stop-color": "var(--accent)", "stop-opacity": .16 }));
            gradiente.appendChild(crear("stop", { offset: "100%", "stop-color": "var(--accent)", "stop-opacity": .01 }));
            defs.appendChild(gradiente);
            svg.appendChild(defs);

            svg.appendChild(crear("path", { d: area, fill: "url(#" + idGradiente + ")" }));
            svg.appendChild(crear("path", {
                d: linea, fill: "none", stroke: "var(--accent)",
                "stroke-width": 2, "stroke-linejoin": "round", "stroke-linecap": "round"
            }));
            svg.appendChild(crear("circle", {
                cx: x(ultimo), cy: y(valores[ultimo]), r: 4,
                fill: "var(--accent)", stroke: "var(--surface)", "stroke-width": 2
            }));

            var cruz = crear("line", {
                x1: 0, x2: 0, y1: padT, y2: padT + altoUtil,
                stroke: "var(--line-2)", "stroke-width": 1, opacity: 0
            });
            svg.appendChild(cruz);
            var punto = crear("circle", {
                r: 4, fill: "var(--accent)", stroke: "var(--surface)", "stroke-width": 2, opacity: 0
            });
            svg.appendChild(punto);

            geo = { ancho: ancho, padL: padL, anchoUtil: anchoUtil, x: x, y: y, cruz: cruz, punto: punto };
        }

        pintar();

        if (globo) {
            contenedor.addEventListener("mousemove", function (evento) {
                if (!geo) {
                    return;
                }
                var caja = svg.getBoundingClientRect();
                var px = ((evento.clientX - caja.left) / caja.width) * geo.ancho;
                var i = Math.round(((px - geo.padL) / geo.anchoUtil) * ultimo);
                i = Math.min(Math.max(i, 0), ultimo);

                geo.cruz.setAttribute("x1", geo.x(i));
                geo.cruz.setAttribute("x2", geo.x(i));
                geo.cruz.setAttribute("opacity", 1);
                geo.punto.setAttribute("cx", geo.x(i));
                geo.punto.setAttribute("cy", geo.y(valores[i]));
                geo.punto.setAttribute("opacity", 1);

                globo.textContent = etiquetas[i] + " · " + valores[i] + (unidad ? " " + unidad : "");
                globo.style.left = ((geo.x(i) / geo.ancho) * caja.width) + "px";
                globo.style.top = ((geo.y(valores[i]) / ALTO) * caja.height - 8) + "px";
                globo.classList.add("on");
            });

            contenedor.addEventListener("mouseleave", function () {
                if (!geo) {
                    return;
                }
                geo.cruz.setAttribute("opacity", 0);
                geo.punto.setAttribute("opacity", 0);
                globo.classList.remove("on");
            });
        }

        /* Al cambiar el ancho hay que repintar: el viewBox depende de él. */
        var temporizador = null;
        function repintar() {
            clearTimeout(temporizador);
            temporizador = setTimeout(pintar, 120);
        }

        if (typeof ResizeObserver === "function") {
            new ResizeObserver(repintar).observe(contenedor);
        } else {
            window.addEventListener("resize", repintar);
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("svg[data-grafica]").forEach(preparar);
    });
})();
