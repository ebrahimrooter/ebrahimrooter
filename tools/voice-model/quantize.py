"""int8 copy of an onnx model (4x smaller, same accuracy for Shenava: tools/voice-model/stt-report.txt),
keeping the settings sherpa-onnx reads from the model (vocab size, normalize_type ...).
   python3 quantize.py in.onnx out.onnx"""
import sys
import onnx
from onnxruntime.quantization import QuantType, quantize_dynamic

src, dst = sys.argv[1], sys.argv[2]
quantize_dynamic(src, dst, weight_type=QuantType.QUInt8)
a, b = onnx.load(src, load_external_data=False), onnx.load(dst)
have = {p.key for p in b.metadata_props}
for p in a.metadata_props:
    if p.key not in have:
        b.metadata_props.add(key=p.key, value=p.value)
onnx.save(b, dst)
