Option Explicit
On Error Resume Next

Dim args, masterPath, picturePath, outputPath
Dim app, format, pageSetup, picture, fso
Set args = WScript.Arguments

If args.Count < 3 Then
  WScript.Echo "BT_ERROR stage=args message=expected master,picture,output"
  WScript.Quit 2
End If

masterPath = args(0)
picturePath = args(1)
outputPath = args(2)
Set fso = CreateObject("Scripting.FileSystemObject")

If Not fso.FileExists(masterPath) Then
  WScript.Echo "BT_ERROR stage=master message=file_not_found"
  WScript.Quit 3
End If

If Not fso.FileExists(picturePath) Then
  WScript.Echo "BT_ERROR stage=picture message=file_not_found"
  WScript.Quit 4
End If

If fso.FileExists(outputPath) Then fso.DeleteFile outputPath, True
Set app = CreateObject("BarTender.Application")
If Err.Number <> 0 Then
  WScript.Echo "BT_ERROR stage=create_application code=" & Err.Number
  WScript.Quit 10
End If

app.Visible = False
Err.Clear
Set format = app.Formats.Open(masterPath, False, "")
If Err.Number <> 0 Or format Is Nothing Then
  WScript.Echo "BT_ERROR stage=open_master code=" & Err.Number
  app.Quit 1
  WScript.Quit 11
End If

If format.Objects.Count <> 1 Then
  WScript.Echo "BT_ERROR stage=master_contract message=expected_one_object"
  format.Close 1
  app.Quit 1
  WScript.Quit 12
End If

Set picture = format.Objects.Item(1)
If picture.Type <> 6 Then
  WScript.Echo "BT_ERROR stage=master_contract message=expected_picture_object"
  format.Close 1
  app.Quit 1
  WScript.Quit 13
End If
Set pageSetup = format.PageSetup
pageSetup.LabelColumns = 1
pageSetup.LabelRows = 1
pageSetup.MarginLeft(3) = 0
pageSetup.MarginRight(3) = 0
pageSetup.MarginTop(3) = 0
pageSetup.MarginBottom(3) = 0
pageSetup.PaperWidth(3) = 50
pageSetup.PaperHeight(3) = 30
pageSetup.LabelWidth(3) = 50
pageSetup.LabelHeight(3) = 30
pageSetup.LabelSizeManual = True

picture.PreserveAspectRatio = False
picture.X = 0
picture.Y = 0
picture.Width = 50
picture.Height = 30

Err.Clear
picture.PicturePath = picturePath
If Err.Number <> 0 Then
  WScript.Echo "BT_ERROR stage=picture_replace code=" & Err.Number
  format.Close 1
  app.Quit 1
  WScript.Quit 14
End If

format.IdenticalCopiesOfLabel = 1
format.NumberSerializedLabels = 1
format.SaveAsVersion = 1010
Err.Clear
format.SaveAs outputPath, True
If Err.Number <> 0 Then
  WScript.Echo "BT_ERROR stage=save_as code=" & Err.Number
  format.Close 1
  app.Quit 1
  WScript.Quit 15
End If

Dim versionText
versionText = app.Version
format.Close 1
app.Quit 1

Set app = CreateObject("BarTender.Application")
app.Visible = False
Err.Clear
Set format = app.Formats.Open(outputPath, False, "")
If Err.Number <> 0 Or format Is Nothing Then
  WScript.Echo "BT_ERROR stage=reopen_output code=" & Err.Number
  app.Quit 1
  WScript.Quit 16
End If

Set pageSetup = format.PageSetup
If Abs(pageSetup.LabelWidth(3) - 50) > 0.05 Or Abs(pageSetup.LabelHeight(3) - 30) > 0.05 Then
  WScript.Echo "BT_ERROR stage=size_check width=" & pageSetup.LabelWidth(3) & " height=" & pageSetup.LabelHeight(3)
  format.Close 1
  app.Quit 1
  WScript.Quit 17
End If
If Not pageSetup.Valid Then
  WScript.Echo "BT_ERROR stage=page_setup message=invalid"
  format.Close 1
  app.Quit 1
  WScript.Quit 18
End If

WScript.Echo "BT_OK version=" & versionText & " label_mm=" & pageSetup.LabelWidth(3) & "x" & pageSetup.LabelHeight(3)
format.Close 1
app.Quit 1
WScript.Quit 0
