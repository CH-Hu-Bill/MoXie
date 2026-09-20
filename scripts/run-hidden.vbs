' Launch run.bat with a hidden window (used by Task Scheduler to avoid a console flash)
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh  = CreateObject("WScript.Shell")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
root = fso.GetParentFolderName(scriptDir)
sh.Run """" & root & "\scripts\run.bat""", 0, False
