# PIPELINE IA AUTOMATICO PARA VIDEOS (SISTEMAX)

## Objetivo
Generar videos de capacitacion por app de forma semi automatica:
1. Guion con OpenAI (`gpt-4.1`).
2. Voz con ElevenLabs.
3. Video final con FFmpeg.

## Requisitos
1. `curl` y `jq` instalados.
2. `ffmpeg` instalado.
3. Variables de entorno:
```bash
export OPENAI_API_KEY="tu_api_key_openai"
export OPENAI_MODEL="gpt-4.1"
export ELEVENLABS_API_KEY="tu_api_key_elevenlabs"
export ELEVENLABS_VOICE_ID="dlGxemPxFMTY7iXagmOj"
```

## Estructura esperada por app
Ejemplo para POS:
```text
video_src/pos/
  guia.md
  slides/
    01.png
    02.png
    03.png
```

## Flujo rapido (1 app)
1. Generar guion:
```bash
bash scripts/video_ai/01_generar_guion_openai.sh \
  video_src/pos/guia.md \
  video_out/pos/guion.txt
```

2. Generar audio:
```bash
bash scripts/video_ai/02_generar_audio_elevenlabs.sh \
  video_out/pos/guion.txt \
  video_out/pos/audio.mp3
```

3. Render video:
```bash
bash scripts/video_ai/03_render_video_ffmpeg.sh \
  video_src/pos/slides \
  video_out/pos/audio.mp3 \
  video_out/pos/video.mp4
```

## Flujo lote (varias apps)
Defini apps y ejecuta:
```bash
bash scripts/video_ai/04_lote_apps.sh pos misventas cajas productos
```

`04_lote_apps.sh` espera para cada app:
- `video_src/<app>/guia.md`
- `video_src/<app>/slides/*.png|*.jpg|*.jpeg`

Y genera:
- `video_out/<app>/guion.txt`
- `video_out/<app>/audio.mp3`
- `video_out/<app>/video.mp4`

## Recomendaciones de velocidad y calidad
1. Guion corto: 700 a 1200 palabras.
2. 6 a 10 slides por video.
3. Resolucion slides: 1920x1080.
4. Narracion: frases cortas y directas.

## Errores comunes
1. `OPENAI_API_KEY` no configurado.
2. `ELEVENLABS_API_KEY` no configurado.
3. `ffmpeg` no instalado.
4. Sin imagenes en `slides/`.

## Instalar ffmpeg (Ubuntu/Debian)
```bash
sudo apt-get update && sudo apt-get install -y ffmpeg
```
