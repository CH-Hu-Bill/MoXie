' Launch run.bat with a hidden window (used by Task Scheduler to avoid a console flash)
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh  = CreateObject("WScript.Shell")
root = fso.GetParentFolderName(WScript.ScriptFullName)
sh.Run """" & root & "\run.bat""", 0, False
