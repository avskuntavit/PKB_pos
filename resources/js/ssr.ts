import { createInertiaApp } from '@inertiajs/vue3'
import createServer from '@inertiajs/vue3/server'
import { renderToString } from '@vue/server-renderer'
import { createSSRApp, h, type DefineComponent } from 'vue'

const appName = process.env.VITE_APP_NAME || 'PKB POS'

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: (title) => (title ? `${title} — ${appName}` : appName),
        resolve: (name) => {
            const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue', { eager: true })
            return pages[`./pages/${name}.vue`]
        },
        setup({ App, props, plugin }) {
            return createSSRApp({ render: () => h(App, props) }).use(plugin)
        },
    }),
)
