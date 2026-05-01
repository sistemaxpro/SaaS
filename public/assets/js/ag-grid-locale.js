(function () {
    const localeEs = {
        page: 'Página',
        more: 'Más',
        to: 'a',
        of: 'de',
        next: 'Siguiente',
        last: 'Último',
        first: 'Primero',
        previous: 'Anterior',
        pageSizeSelectorLabel: 'Tamaño de página',
        loadingOoo: 'Cargando...',
        loading: 'Cargando...',
        noRowsToShow: 'No hay registros para mostrar',
        enabled: 'Habilitado',
        selectAll: 'Seleccionar todo',
        searchOoo: 'Buscar...',
        blanks: '(Vacíos)',
        noMatches: 'Sin coincidencias',
        filterOoo: 'Filtrar...',
        equals: 'Igual a',
        notEqual: 'Distinto de',
        empty: 'Elegir uno',
        lessThan: 'Menor que',
        greaterThan: 'Mayor que',
        lessThanOrEqual: 'Menor o igual que',
        greaterThanOrEqual: 'Mayor o igual que',
        inRange: 'Entre',
        inRangeStart: 'Desde',
        inRangeEnd: 'Hasta',
        contains: 'Contiene',
        notContains: 'No contiene',
        startsWith: 'Comienza con',
        endsWith: 'Termina con',
        blank: 'Vacío',
        notBlank: 'No vacío',
        before: 'Antes',
        after: 'Después',
        andCondition: 'Y',
        orCondition: 'O',
        dateFormatOoo: 'aaaa-mm-dd',
        applyFilter: 'Aplicar',
        resetFilter: 'Restablecer',
        clearFilter: 'Limpiar',
        cancelFilter: 'Cancelar',
        textFilter: 'Filtro de texto',
        numberFilter: 'Filtro numérico',
        dateFilter: 'Filtro de fecha',
        setFilter: 'Filtro de valores',
        columns: 'Columnas',
        filters: 'Filtros',
        pivotMode: 'Modo pivote',
        groups: 'Grupos',
        rowGroupColumns: 'Columnas agrupadas',
        rowGroupColumnsEmptyMessage: 'Arrastre aquí para agrupar',
        values: 'Valores',
        valueColumns: 'Columnas de valor',
        pivots: 'Pivotes',
        pivotColumns: 'Columnas pivote',
        groupColumns: 'Columnas de grupo',
        dragHereToAggregate: 'Arrastre aquí para agregar',
        dragHereToGroup: 'Arrastre aquí para agrupar',
        groupBy: 'Agrupar por',
        ungroupBy: 'Quitar agrupación por',
        pinColumn: 'Fijar columna',
        pinLeft: 'Fijar a la izquierda',
        pinRight: 'Fijar a la derecha',
        noPin: 'Sin fijar',
        valueAggregation: 'Agregar valor',
        autosizeThisColumn: 'Autoajustar esta columna',
        autosizeAllColumns: 'Autoajustar todas las columnas',
        resetColumns: 'Restablecer columnas',
        expandAll: 'Expandir todo',
        collapseAll: 'Contraer todo',
        copy: 'Copiar',
        copyWithHeaders: 'Copiar con encabezados',
        copyWithGroupHeaders: 'Copiar con grupos',
        cut: 'Cortar',
        paste: 'Pegar',
        export: 'Exportar',
        csvExport: 'Exportar CSV',
        excelExport: 'Exportar Excel',
        separator: 'Separador',
        ariaSearch: 'Buscar',
        ariaFilterColumnsInput: 'Filtrar columnas',
        ariaRowSelect: 'Seleccionar fila',
        ariaRowToggleSelection: 'Alternar selección de fila',
        ariaColumnSelectAll: 'Seleccionar todas las columnas',
        ariaDateFilterInput: 'Filtro de fecha',
        ariaNumberFilterInput: 'Filtro numérico',
        ariaTextFilterInput: 'Filtro de texto'
    };

    const localeEn = {
        page: 'Page',
        more: 'More',
        to: 'to',
        of: 'of',
        next: 'Next',
        last: 'Last',
        first: 'First',
        previous: 'Previous',
        pageSizeSelectorLabel: 'Page size',
        loadingOoo: 'Loading...',
        loading: 'Loading...',
        noRowsToShow: 'No rows to show'
    };

    const localePt = {
        page: 'Página',
        more: 'Mais',
        to: 'até',
        of: 'de',
        next: 'Próxima',
        last: 'Última',
        first: 'Primeira',
        previous: 'Anterior',
        pageSizeSelectorLabel: 'Tamanho da página',
        loadingOoo: 'Carregando...',
        loading: 'Carregando...',
        noRowsToShow: 'Nenhum registro para exibir'
    };

    const locales = {
        es: localeEs,
        en: Object.assign({}, localeEs, localeEn),
        pt: Object.assign({}, localeEs, localePt)
    };

    function detectLanguage() {
        const lang = String(
            window.SmxI18n?.getLocale?.() ||
            window.__SISTEMAX_LOCALE__?.locale ||
            localStorage.getItem('smx_locale') ||
            ((document.cookie.match(/(?:^|; )smx_locale=([^;]*)/) || [])[1] ? decodeURIComponent((document.cookie.match(/(?:^|; )smx_locale=([^;]*)/) || [])[1]) : '') ||
            (document.documentElement && document.documentElement.lang) ||
            navigator.language ||
            navigator.userLanguage ||
            'es'
        ).toLowerCase();

        if (lang.startsWith('pt')) return 'pt';
        if (lang.startsWith('en')) return 'en';
        return 'es';
    }

    function getLocaleText(lang) {
        const resolved = String(lang || detectLanguage()).toLowerCase();
        if (resolved.startsWith('pt')) return locales.pt;
        if (resolved.startsWith('en')) return locales.en;
        return locales.es;
    }

    function withLocale(options, lang) {
        return Object.assign({}, options || {}, {
            localeText: getLocaleText(lang)
        });
    }

    window.SmxAgGridLocale = {
        detectLanguage,
        getLocaleText,
        withLocale
    };
})();
