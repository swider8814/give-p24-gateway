(() => {
    let settings = {};

    function Fields() {
        return window.wp.element.createElement(
            'div',
            {className: 'give-p24-help-text'},
            window.wp.element.createElement('p', {style: {marginBottom: 0}}, settings.message)
        );
    }

    window.givewp.gateways.register({
        id: 'przelewy24',
        initialize() {
            settings = this.settings || {};
        },
        Fields() {
            return window.wp.element.createElement(Fields);
        },
    });
})();
