#!/usr/bin/env python3
"""Mimics the Arduino IDE sketch preprocessor so host tests catch the same
errors: adds '#include <Arduino.h>' on top and a prototype for every
function, placed just before the first function definition (that is where
arduino-cli puts them - before any struct defined later in the file).

usage: arduino_preprocess.py input.ino output.cpp
"""
import re
import sys

src = open(sys.argv[1], encoding='utf-8').read()
# Top-level function definitions: start at column 0, end with "{" on the same line.
func = re.compile(r'^(?!(?:if|for|while|switch|else|return|struct|class|enum|typedef)\b)'
                  r'([A-Za-z_][\w:<>]*(?:[ \t]+[A-Za-z_][\w:<>]*)*[ \t\*&]+)([A-Za-z_]\w*)[ \t]*\(([^;{}]*)\)[ \t]*\{',
                  re.M)
protos, first = [], None
for m in func.finditer(src):
    if m.group(2) in ('setup', 'loop'):
        pass
    protos.append(f'{m.group(1).strip()} {m.group(2)}({m.group(3)});')
    if first is None:
        first = m.start()
if first is None:
    sys.exit('no functions found')
out = '#include <Arduino.h>\n' + src[:first] + '\n'.join(protos) + '\n#line 1 "prototypes-end"\n' + src[first:]
open(sys.argv[2], 'w', encoding='utf-8').write(out)
print(f'{sys.argv[1]}: {len(protos)} prototypes inserted')
