# Orb videos for the iPhone app's Picture in Picture

`ios/BankAssistant/Resources/Orb/orb-{idle,listening,processing,speaking}.mp4`
are 4-second seamless loops (480×480, 30 fps, silent, H.264) drawn by `orb.html`.

```
npm i playwright            # Chromium
node orbvid.js "$PWD"       # → idle/ listening/ processing/ speaking/ PNG frames
for p in idle listening processing speaking; do
  ffmpeg -y -framerate 30 -i $p/%04d.png -c:v libx264 -pix_fmt yuv420p -profile:v main -crf 27 -tag:v avc1 \
    -movflags +faststart ../../ios/BankAssistant/Resources/Orb/orb-$p.mp4
done
```
