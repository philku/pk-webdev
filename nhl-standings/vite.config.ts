import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: '../public/nhl-build',
    emptyDirOnBuild: true,
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        entryFileNames: 'nhl-app.js',
        assetFileNames: 'nhl-app.[ext]',
      },
    },
  },
})
