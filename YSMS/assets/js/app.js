$(document).ready(function() {
    // Shared profile dropdown
    $(document).on('click', '.profile-menu-trigger', function(e) {
        e.stopPropagation();
        const wrap = $(this).closest('.profile-menu-wrap');
        $('.profile-menu-wrap').not(wrap).removeClass('open').find('.profile-menu-trigger').attr('aria-expanded', 'false');
        wrap.toggleClass('open');
        $(this).attr('aria-expanded', wrap.hasClass('open') ? 'true' : 'false');
    });
    $(document).on('click', function() {
        $('.profile-menu-wrap').removeClass('open').find('.profile-menu-trigger').attr('aria-expanded', 'false');
    });

    // Responsive existing navigation drawer
    function closeSidebar() {
        $('.bottom-nav-bar, .sidebar-screen-overlay').removeClass('open');
        $('.hamburger-menu-btn').attr('aria-expanded', 'false');
    }
    $(document).on('click', '.hamburger-menu-btn', function() {
        $('.bottom-nav-bar, .sidebar-screen-overlay').toggleClass('open');
        $(this).attr('aria-expanded', $('.bottom-nav-bar').hasClass('open') ? 'true' : 'false');
    });
    $(document).on('click', '.sidebar-screen-overlay, .bottom-nav-bar .nav-item-btn', closeSidebar);

    // Global FAB Bottom Drawer Action Animation Engine Match
    $('#globalFabTrigger').on('click', function() {
        $('#fabActionOverlay').css('display', 'flex');
        setTimeout(function() {
            $('#fabPopupSheet').css('transform', 'translateY(0)');
        }, 10);
    });

    function closeFab() {
        $('#fabPopupSheet').css('transform', 'translateY(100%)');
        setTimeout(function() {
            $('#fabActionOverlay').css('display', 'none');
        }, 300);
    }

    $('#closeFabBtn, #fabActionOverlay').on('click', function(e) {
        if (e.target === this || this.id === 'closeFabBtn') {
            closeFab();
        }
    });

    // Reliable local search, sort and filter controls
    // Use .attr() for string data-* values — jQuery .data() can mangle long strings / HTML entities
    function cardAttr($el, name) {
        return String($el.attr('data-' + name) || '').trim();
    }

    function updateSurveyList(scope) {
        const $scope = $(scope);
        const container = $scope.find('[data-list-container]');
        const query = String($scope.find('[data-list-search]').val() || '').toLowerCase().trim();
        const type = String($scope.find('[data-filter-type]').val() || '');
        const place = String($scope.find('[data-filter-place]').val() || '');
        const client = String($scope.find('[data-filter-client]').val() || '');
        const status = String($scope.find('[data-filter-status]').val() || '');
        const surveyor = String($scope.find('[data-filter-surveyor]').val() || '');
        const sort = String($scope.find('[data-list-sort]').val() || 'newest');
        const cards = container.find('[data-survey-card]').get();

        const queryParts = query ? query.split(/\s+/).filter(Boolean) : [];

        cards.sort(function(a, b) {
            const $a = $(a), $b = $(b);
            const nameA = cardAttr($a, 'name').toLowerCase();
            const nameB = cardAttr($b, 'name').toLowerCase();
            const dateA = Number(cardAttr($a, 'date') || 0);
            const dateB = Number(cardAttr($b, 'date') || 0);
            if (sort === 'name-asc') return nameA.localeCompare(nameB);
            if (sort === 'name-desc') return nameB.localeCompare(nameA);
            if (sort === 'oldest') return dateA - dateB;
            return dateB - dateA; // newest
        });
        container.append(cards);

        let visible = 0;
        cards.forEach(function(card) {
            const $card = $(card);
            const haystack = cardAttr($card, 'search').toLowerCase();
            const matchQuery = !queryParts.length || queryParts.every(function(part) {
                return haystack.indexOf(part) !== -1;
            });
            const show = matchQuery
                && (!type || cardAttr($card, 'type') === type)
                && (!place || cardAttr($card, 'place') === place)
                && (!client || cardAttr($card, 'client') === client)
                && (!status || cardAttr($card, 'status') === status)
                && (!surveyor || cardAttr($card, 'surveyor') === surveyor);
            $card.toggle(show);
            if (show) visible++;
        });
        $scope.find('[data-list-empty]').toggle(visible === 0);
        $scope.find('[data-list-count]').text(visible);
    }

    $(document).on('input change', '[data-list-search], [data-filter-type], [data-filter-place], [data-filter-client], [data-filter-status], [data-filter-surveyor], [data-list-sort]', function() {
        updateSurveyList($(this).closest('[data-list-scope]'));
    });
    $(document).on('click', '[data-filter-toggle]', function() {
        const panel = $(this).closest('[data-list-scope]').find('[data-controls-panel]');
        panel.toggleClass('open');
        $(this).attr('aria-expanded', panel.hasClass('open') ? 'true' : 'false');
    });
    $(document).on('click', '[data-clear-filters]', function() {
        const scope = $(this).closest('[data-list-scope]');
        scope.find('[data-list-search]').val('');
        scope.find('[data-filter-type], [data-filter-place], [data-filter-client], [data-filter-status], [data-filter-surveyor]').val('');
        scope.find('[data-list-sort]').val('newest');
        updateSurveyList(scope);
    });
});
