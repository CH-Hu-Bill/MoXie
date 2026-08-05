import 'package:flutter/material.dart';
import 'package:flutter_quill/flutter_quill.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:myapp/widgets/rich_text_editor.dart';

void main() {
  testWidgets('loads class-based color and re-saves it as inline', (tester) async {
    final key = GlobalKey<RichTextEditorState>();
    const classHtml = '<p><span class="ql-color-ff0000">红字</span>普通</p>';

    await tester.pumpWidget(MaterialApp(
      localizationsDelegates: const [FlutterQuillLocalizations.delegate],
      home: RichTextEditor(key: key, initialHtml: classHtml),
    ));
    await tester.pumpAndSettle();

    final out = key.currentState!.getHtml();
    expect(out, contains('color:#ff0000'));

    // Re-open (as when re-editing a saved entry).
    key.currentState!.setHtml(out);
    await tester.pump();
    final out2 = key.currentState!.getHtml();
    expect(out2, contains('color:#ff0000'));
  });
}
