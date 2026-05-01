# Video SistemaX Stock Ad

## Entregables

- Guion: [video_src/sistemax_stock_ads/guia.md](/var/www/html/sistemaxpro-dev/video_src/sistemax_stock_ads/guia.md)
- Prompts visuales: [video_src/sistemax_stock_ads/prompts.md](/var/www/html/sistemaxpro-dev/video_src/sistemax_stock_ads/prompts.md)
- Captions: [video_src/sistemax_stock_ads/captions.srt](/var/www/html/sistemaxpro-dev/video_src/sistemax_stock_ads/captions.srt)
- Slides base: [video_src/sistemax_stock_ads/slides](/var/www/html/sistemaxpro-dev/video_src/sistemax_stock_ads/slides)
- Audio ElevenLabs: [video_out/sistemax_stock_ads/audio.mp3](/var/www/html/sistemaxpro-dev/video_out/sistemax_stock_ads/audio.mp3)
- Video vertical base: [video_out/sistemax_stock_ads/video_vertical.mp4](/var/www/html/sistemaxpro-dev/video_out/sistemax_stock_ads/video_vertical.mp4)
- Audio extendido 45s: [video_out/sistemax_stock_ads/audio_slow_45s.mp3](/var/www/html/sistemaxpro-dev/video_out/sistemax_stock_ads/audio_slow_45s.mp3)
- Video vertical 45s: [video_out/sistemax_stock_ads/video_vertical_45s.mp4](/var/www/html/sistemaxpro-dev/video_out/sistemax_stock_ads/video_vertical_45s.mp4)

## Que ya esta listo

1. Locucion completa generada con ElevenLabs.
2. Video vertical 9:16 renderizado en MP4.
3. Captions preparados en SRT.
4. Prompts por escena para reemplazar slides por clips de Runway o Kling.

La version base actualmente renderizada dura aproximadamente 31 segundos.

Tambien quedo una segunda variante de aproximadamente 45 segundos usando la misma locucion desacelerada de forma leve para anuncios mas pausados.

## Como llevarlo a version premium

1. Genera 1 clip por escena usando los prompts de `prompts.md`.
2. Reemplaza cada slide por su clip correspondiente.
3. Mantene el audio `audio.mp3`.
4. Reusa `captions.srt` o recrea captions animados en CapCut.
5. Exporta en `1080x1920`, `30 fps`, `H.264`.

## Orden visual recomendado

1. Problema de stock.
2. Dashboard principal.
3. Entradas y salidas.
4. Alertas y reportes.
5. Beneficios operativos.
6. CTA final con logo.

## Nota

El archivo `video_vertical.mp4` es una version base ya reproducible. Sirve para:

- aprobar guion y timing
- validar locucion
- revisar estructura comercial
- usarlo como maqueta antes de pasar a clips IA premium
